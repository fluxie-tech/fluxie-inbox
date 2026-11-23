<?php

namespace Tests\Feature\Reset;

use App\Jobs\ResetSystemJob;
use App\Models\Channel;
use App\Models\Message;
use App\Models\SystemUpdate;
use App\Services\Backup\ManualBackupState;
use App\Services\Backup\Restore\AdminDatabase;
use App\Services\Backup\Restore\Maintenance;
use App\Services\Backup\Restore\RestoreProgress;
use App\Services\Backup\Restore\RestoreRejected;
use App\Services\Backup\Restore\StoredDatabase;
use App\Services\Reset\ResetSystem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ResetSystemTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $maintenance;

    private MockInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fluxie.allow_reset' => true, 'services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'k']);
        Storage::fake('backups');
        Storage::fake(config()->string('media.disk'));
        Sleep::fake();
        Log::spy();

        $this->maintenance = $this->mock(Maintenance::class);
        $this->maintenance->shouldReceive('problem')->andReturn(null)->byDefault();
        $this->admin = $this->mock(AdminDatabase::class);
        $this->admin->shouldReceive('stored')->andReturn([new StoredDatabase('fluxie_antes_202611121500', CarbonImmutable::now(), 1)])->byDefault();
        $this->admin->shouldReceive('staging')->andReturn([new StoredDatabase('fluxie_restaurando_202611121500', CarbonImmutable::now(), 1)])->byDefault();
    }

    private function service(): ResetSystem
    {
        return app(ResetSystem::class);
    }

    private function begun(): void
    {
        app(RestoreProgress::class)->begin('Zerando o sistema', ResetSystem::STEPS, 'reset');
    }

    public function test_run_apaga_tudo_na_ordem(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        Cache::forever(ResetSystem::SESSIONS, ['main', 'vendas']);
        Cache::forever(ResetSystem::STARTED, true);
        foreach ([Message::mediaDisk(), Storage::disk('backups')] as $disk) {
            $disk->put('raiz.txt', 'x');
            $disk->put('pasta/sub/a.txt', 'x');
            $disk->put('restauracao/b.txt', 'x');
        }
        $this->maintenance->shouldReceive('enter')->once()->ordered();
        $this->maintenance->shouldReceive('clearQueues')->once()->ordered();
        $this->admin->shouldReceive('drop')->once()->with('fluxie_antes_202611121500')->ordered();
        $this->admin->shouldReceive('drop')->once()->with('fluxie_restaurando_202611121500')->ordered();
        $this->maintenance->shouldReceive('fresh')->once()->ordered();
        $this->maintenance->shouldReceive('restartWorkers')->once()->ordered();
        $this->maintenance->shouldReceive('leave')->once()->ordered();
        $this->begun();

        $this->service()->run();

        $this->assertSame([], Message::mediaDisk()->allFiles());
        $this->assertSame([], Storage::disk('backups')->allFiles());
        foreach (['main', 'vendas'] as $name) {
            Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === "http://waha.test:3000/api/{$name}/logout");
            Http::assertSent(fn ($r) => $r->method() === 'DELETE' && $r->url() === "http://waha.test:3000/api/sessions/{$name}");
        }
        Sleep::assertSlept(fn ($d) => (int) $d->totalSeconds === 30, 1);
        $state = app(RestoreProgress::class)->get();
        $this->assertSame('done', $state['status']);
        $this->assertSame('reset', $state['kind']);
        $this->assertFalse(Cache::has(ResetSystem::STARTED));
        $this->assertTrue(Cache::lock(ResetSystem::LOCK, 5)->get(), 'o lock foi solto');
    }

    public function test_sessao_ja_apagada_no_waha_nao_falha(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        Cache::forever(ResetSystem::SESSIONS, ['main']);
        $this->maintenance->shouldReceive('enter', 'clearQueues', 'fresh', 'restartWorkers', 'leave');
        $this->admin->shouldReceive('drop');
        $this->begun();

        $this->service()->run();

        $this->assertSame('done', app(RestoreProgress::class)->get()['status']);
    }

    public function test_falha_num_passo_marca_falhou_fica_em_manutencao_e_mostra_o_comando(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        Cache::forever(ResetSystem::STARTED, true);
        $this->maintenance->shouldReceive('enter', 'clearQueues');
        $this->maintenance->shouldReceive('fresh')->andThrow(new RestoreRejected('Não foi possível recriar o banco.'));
        $this->maintenance->shouldNotReceive('leave');
        $this->admin->shouldReceive('drop');
        $this->begun();

        try {
            $this->service()->run();
            $this->fail('A falha deveria subir.');
        } catch (RestoreRejected) {
        }

        $state = app(RestoreProgress::class)->get();
        $this->assertSame('failed', $state['status']);
        $this->assertStringContainsString('app:reset-system --continue', $state['message']);
        $this->assertTrue($this->service()->canContinue());
        $this->assertTrue(Cache::lock(ResetSystem::LOCK, 5)->get(), 'o lock foi solto');
    }

    public function test_continuar_usa_as_sessoes_guardadas_mesmo_sem_canais_no_banco(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->assertSame(0, Channel::query()->count());
        Cache::forever(ResetSystem::SESSIONS, ['antiga']);
        $this->maintenance->shouldReceive('enter', 'clearQueues', 'fresh', 'restartWorkers', 'leave');
        $this->admin->shouldReceive('drop');
        $this->begun();

        $this->service()->run();

        Http::assertSent(fn ($r) => $r->url() === 'http://waha.test:3000/api/antiga/logout');
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && $r->url() === 'http://waha.test:3000/api/sessions/antiga');
    }

    public function test_lock_ocupado_recusa_sem_tocar_em_nada(): void
    {
        $lock = Cache::lock(ResetSystem::LOCK, 60);
        $lock->get();
        $this->maintenance->shouldNotReceive('enter');
        $this->begun();

        try {
            $this->service()->run();
            $this->fail('O lock ocupado deveria recusar.');
        } catch (RestoreRejected) {
        } finally {
            $lock->release();
        }

        $state = app(RestoreProgress::class)->get();
        $this->assertSame('failed', $state['status']);
        $this->assertSame('Já há uma restauração ou zerar em andamento.', $state['message']);
    }

    public function test_falha_depois_do_cache_ainda_deixa_continuar(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        Cache::forever(ResetSystem::STARTED, true);
        $this->maintenance->shouldReceive('enter', 'clearQueues', 'fresh', 'restartWorkers');
        $this->maintenance->shouldReceive('leave')->andThrow(new RuntimeException('redis'));
        $this->admin->shouldReceive('drop');
        $this->begun();

        try {
            $this->service()->run();
            $this->fail('A falha deveria subir.');
        } catch (RuntimeException) {
        }

        $this->assertFalse(Cache::has(ResetSystem::STARTED), 'o flush já levou o STARTED');
        $this->assertTrue($this->service()->canContinue());
        $this->assertStringContainsString('app:reset-system --continue', app(RestoreProgress::class)->get()['message']);
    }

    public function test_logout_com_erro_que_nao_e_404_segue_para_apagar_a_sessao(): void
    {
        Http::fake(['*/logout' => Http::response([], 422), '*' => Http::response([], 200)]);
        Cache::forever(ResetSystem::SESSIONS, ['main']);
        $this->maintenance->shouldReceive('enter', 'clearQueues', 'fresh', 'restartWorkers', 'leave');
        $this->admin->shouldReceive('drop');
        $this->begun();

        $this->service()->run();

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && $r->url() === 'http://waha.test:3000/api/sessions/main');
        $this->assertSame('done', app(RestoreProgress::class)->get()['status']);
    }

    public function test_o_log_final_traz_as_contagens(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        Cache::forever(ResetSystem::SESSIONS, ['main', 'vendas']);
        Message::mediaDisk()->put('a.txt', 'x');
        Message::mediaDisk()->put('p/b.txt', 'x');
        foreach (['a', 'b', 'p/c', 'p/d', 'q/e'] as $name) {
            Storage::disk('backups')->put($name.'.txt', 'x');
        }
        $this->maintenance->shouldReceive('enter', 'clearQueues', 'fresh', 'restartWorkers', 'leave');
        $this->admin->shouldReceive('drop');
        $this->begun();

        $this->service()->run();

        Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, '2 canais') && str_contains($m, '2 mídias') && str_contains($m, '5 backups'))->once();
    }

    public function test_problem_variavel_desligada(): void
    {
        config(['fluxie.allow_reset' => false]);

        $this->assertSame('O zerar está desligado nesta instalação.', $this->service()->problem());
    }

    public function test_problem_do_ambiente_vem_da_manutencao(): void
    {
        $this->maintenance->shouldReceive('problem')->andReturn('Sem Redis.');

        $this->assertSame('Sem Redis.', $this->service()->problem(continuing: true));
    }

    public function test_problem_continuando_ignora_so_o_ja_em_manutencao(): void
    {
        $this->maintenance->shouldReceive('problem')->andReturn(Maintenance::ALREADY_DOWN);

        $this->assertNotNull($this->service()->problem());
        $this->assertNull($this->service()->problem(continuing: true));
    }

    public function test_problem_restauracao_rodando(): void
    {
        app(RestoreProgress::class)->begin('Restaurando o banco', ['inspect']);

        $this->assertNotNull($this->service()->problem());
        $this->assertNull($this->service()->problem(continuing: true));
    }

    public function test_problem_atualizacao_aberta(): void
    {
        SystemUpdate::start(null, '0.1.0', '0.2.0');

        $this->assertNotNull($this->service()->problem());
    }

    public function test_problem_backup_manual_rodando(): void
    {
        app(ManualBackupState::class)->markStarted();

        $this->assertNotNull($this->service()->problem());
    }

    public function test_problem_waha_fora_do_ar(): void
    {
        Channel::factory()->create(['waha_session' => 'main']);
        Http::fake(fn () => throw new ConnectionException('fora'));

        $this->assertSame('O WAHA não respondeu. Confira se ele está no ar antes de zerar.', $this->service()->problem());
    }

    public function test_problem_sessao_que_nao_existe_no_waha_passa(): void
    {
        Channel::factory()->create(['waha_session' => 'main']);
        Http::fake(['*' => Http::response([], 404)]);

        $this->assertNull($this->service()->problem());
    }

    public function test_problem_tudo_pronto(): void
    {
        $this->assertNull($this->service()->problem());
    }

    public function test_start_guarda_sessoes_marca_inicio_e_despacha_o_job(): void
    {
        Queue::fake();
        Channel::factory()->create(['waha_session' => 'main']);

        $this->service()->start('Ana <ana@x.com>');

        $this->assertSame(['main'], Cache::get(ResetSystem::SESSIONS));
        $this->assertTrue($this->service()->canContinue());
        $this->assertSame('reset', app(RestoreProgress::class)->get()['kind']);
        Queue::assertPushedOn('history', ResetSystemJob::class);
        Log::shouldHaveReceived('warning')->once();
    }
}
