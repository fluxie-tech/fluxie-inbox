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
use App\Services\Reset\SessionPurger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class ResetSystemTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $maintenance;

    private MockInterface $admin;

    private MockInterface $sessions;

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
        $this->sessions = $this->mock(SessionPurger::class);
        $this->sessions->shouldReceive('purge')->andReturn(0)->byDefault();
        $this->admin = $this->mock(AdminDatabase::class);
        $this->admin->shouldReceive('stored')->andReturn([new StoredDatabase('fluxie_antes_202611121500', CarbonImmutable::now(), 1)])->byDefault();
        $this->admin->shouldReceive('staging')->andReturn([new StoredDatabase('fluxie_restaurando_202611121500', CarbonImmutable::now(), 1)])->byDefault();
    }

    private function service(): ResetSystem
    {
        return app(ResetSystem::class);
    }

    /** O app em manutenção de verdade (o `Maintenance` é mock, então nada o põe no ar ou fora dele). */
    private function down(): void
    {
        $mode = Mockery::mock(MaintenanceMode::class);
        $mode->shouldReceive('active')->andReturn(true);
        $this->app->instance(MaintenanceMode::class, $mode);
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
        $this->sessions->shouldReceive('purge')->once()->ordered();
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
        $this->down();
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
        $this->assertSame('Já há uma restauração ou zerar em andamento. Espere alguns minutos e rode de novo: php artisan app:reset-system --continue', $state['message']);
    }

    public function test_falha_no_reset_up_depois_do_cache_manda_reiniciar_os_workers_e_nao_continuar(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        Cache::forever(ResetSystem::STARTED, true);
        $this->maintenance->shouldReceive('enter', 'clearQueues', 'fresh');
        $this->maintenance->shouldReceive('restartWorkers')->andThrow(new RuntimeException('redis'));
        $this->admin->shouldReceive('drop');
        $this->begun();

        try {
            $this->service()->run();
            $this->fail('A falha deveria subir.');
        } catch (RuntimeException) {
        }

        $message = app(RestoreProgress::class)->get()['message'];
        $this->assertFalse(Cache::has(ResetSystem::STARTED), 'o flush já levou o STARTED');
        $this->assertSame('O sistema voltou ao ar, mas os workers podem não ter reiniciado: rode `php artisan queue:restart` no terminal do app.', $message);
        $this->assertStringNotContainsString('--continue', $message);
        $this->assertSame('failed', app(RestoreProgress::class)->get()['status']);
    }

    public function test_falha_no_leave_depois_do_cache_tambem_manda_reiniciar_os_workers(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->maintenance->shouldReceive('enter', 'clearQueues', 'fresh', 'restartWorkers');
        $this->maintenance->shouldReceive('leave')->andThrow(new RuntimeException('redis'));
        $this->admin->shouldReceive('drop');
        $this->begun();

        try {
            $this->service()->run();
            $this->fail('A falha deveria subir.');
        } catch (RuntimeException) {
        }

        $this->assertStringContainsString('queue:restart', app(RestoreProgress::class)->get()['message']);
    }

    public function test_falha_do_purge_das_sessoes_acontece_antes_do_flush_e_deixa_continuar(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        Cache::forever(ResetSystem::STARTED, true);
        $this->maintenance->shouldReceive('enter', 'clearQueues', 'fresh');
        $this->sessions->shouldReceive('purge')->andThrow(new RuntimeException('redis'));
        $this->admin->shouldReceive('drop');
        $this->begun();

        try {
            $this->service()->run();
            $this->fail('A falha deveria subir.');
        } catch (RuntimeException) {
        }

        $this->assertTrue(Cache::has(ResetSystem::STARTED), 'o cache ainda não foi limpo');
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

    public function test_can_continue_exige_o_sistema_em_manutencao(): void
    {
        Cache::forever(ResetSystem::STARTED, true);

        $this->assertFalse($this->service()->canContinue(), 'sistema no ar');

        $this->down();
        $this->assertTrue($this->service()->canContinue());
    }

    public function test_started_expira_em_24_horas(): void
    {
        Queue::fake();
        Cache::spy();

        $this->service()->start('Ana <ana@x.com>');

        Cache::shouldHaveReceived('put')->withArgs(fn (string $key, mixed $value, mixed $ttl) => $key === ResetSystem::STARTED
            && $ttl > now()->addHours(23)->addMinutes(59)
            && $ttl <= now()->addHours(24))->once();
    }

    public function test_run_junta_as_sessoes_guardadas_com_as_dos_canais_atuais(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        Channel::factory()->create(['waha_session' => 'nova']);
        Cache::forever(ResetSystem::SESSIONS, ['antiga']);
        $this->maintenance->shouldReceive('enter', 'clearQueues', 'fresh', 'restartWorkers', 'leave');
        $this->admin->shouldReceive('drop');
        $this->begun();

        $this->service()->run();

        foreach (['antiga', 'nova'] as $name) {
            Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === "http://waha.test:3000/api/{$name}/logout");
            Http::assertSent(fn ($r) => $r->method() === 'DELETE' && $r->url() === "http://waha.test:3000/api/sessions/{$name}");
        }
    }

    public function test_run_com_a_tabela_de_canais_inexistente_usa_so_as_guardadas(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        Cache::forever(ResetSystem::SESSIONS, ['antiga']);
        DB::statement('DROP TABLE channels CASCADE');
        $this->maintenance->shouldReceive('enter', 'clearQueues', 'fresh', 'restartWorkers', 'leave');
        $this->admin->shouldReceive('drop');
        $this->begun();

        $this->service()->run();

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && $r->url() === 'http://waha.test:3000/api/sessions/antiga');
        $this->assertSame('done', app(RestoreProgress::class)->get()['status']);
    }

    public function test_problem_backup_diario_em_andamento(): void
    {
        $lock = Cache::lock('backup-database', 60);
        $lock->get();

        $this->assertSame('Há um backup em andamento; espere ele terminar.', $this->service()->problem());
        $lock->release();
        $this->assertNull($this->service()->problem());
    }

    public function test_run_segura_o_lock_do_backup_e_o_solta_no_fim(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->maintenance->shouldReceive('enter', 'clearQueues', 'restartWorkers', 'leave');
        $this->maintenance->shouldReceive('fresh')->once()->andReturnUsing(function (): void {
            $this->assertFalse(Cache::lock('backup-database', 5)->get(), 'o backup não pode começar durante o zerar');
        });
        $this->admin->shouldReceive('drop');
        $this->begun();

        $this->service()->run();

        $this->assertTrue(Cache::lock('backup-database', 5)->get(), 'o lock do backup foi solto');
    }

    public function test_run_com_backup_em_andamento_recusa_e_solta_o_lock_da_restauracao(): void
    {
        $backup = Cache::lock('backup-database', 60);
        $backup->get();
        $this->maintenance->shouldNotReceive('enter');
        $this->begun();

        try {
            $this->service()->run();
            $this->fail('O backup em andamento deveria recusar.');
        } catch (RestoreRejected) {
        } finally {
            $backup->release();
        }

        $this->assertStringContainsString('backup em andamento', app(RestoreProgress::class)->get()['message']);
        $this->assertTrue(Cache::lock(ResetSystem::LOCK, 5)->get(), 'o lock da restauração foi solto');
    }

    public function test_o_lock_da_restauracao_dura_mais_que_o_job(): void
    {
        Cache::spy();
        $this->maintenance->shouldReceive('enter', 'clearQueues', 'fresh', 'restartWorkers', 'leave');
        $this->admin->shouldReceive('drop');

        try {
            $this->service()->run();
        } catch (Throwable) {
        }

        Cache::shouldHaveReceived('lock')->with(ResetSystem::LOCK, 900)->once();
    }

    public function test_wipe_local_files_esvazia_o_disco_local_e_a_pasta_tmp(): void
    {
        Storage::fake('local');
        $tmp = sys_get_temp_dir().'/reset-tmp-'.uniqid();
        File::makeDirectory($tmp.'/sub', recursive: true);
        File::put($tmp.'/dump.sql', 'x');
        File::put($tmp.'/sub/outro.sql', 'x');
        config(['backup.tmp_path' => $tmp]);
        foreach (['livewire-tmp/dump.sql', 'anexos/a.png', 'raiz.txt'] as $path) {
            Storage::disk('local')->put($path, 'x');
        }

        try {
            $this->assertSame(5, $this->service()->wipeLocalFiles());

            $this->assertSame([], Storage::disk('local')->allFiles());
            $this->assertSame([], File::allFiles($tmp));
            $this->assertDirectoryExists($tmp);
        } finally {
            File::deleteDirectory($tmp);
        }
    }

    public function test_wipe_local_files_sem_a_pasta_tmp_nao_falha(): void
    {
        Storage::fake('local');
        config(['backup.tmp_path' => sys_get_temp_dir().'/nao-existe-'.uniqid()]);

        $this->assertSame(0, $this->service()->wipeLocalFiles());
    }

    public function test_start_apaga_os_arquivos_locais(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['backup.tmp_path' => sys_get_temp_dir().'/nao-existe-'.uniqid()]);
        Storage::disk('local')->put('livewire-tmp/dump.sql', 'x');

        $this->service()->start('Ana <ana@x.com>');

        $this->assertSame([], Storage::disk('local')->allFiles());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, '1 arquivos locais'))->once();
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
        $this->down();
        $this->assertTrue($this->service()->canContinue());
        $this->assertSame('reset', app(RestoreProgress::class)->get()['kind']);
        Queue::assertPushedOn('history', ResetSystemJob::class);
        Log::shouldHaveReceived('warning')->once();
    }
}
