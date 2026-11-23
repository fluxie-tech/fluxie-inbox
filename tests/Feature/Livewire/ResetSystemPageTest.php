<?php

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Jobs\ResetSystemJob;
use App\Livewire\Settings\System;
use App\Models\Setting;
use App\Models\User;
use App\Services\Reset\ResetSystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\Feature\Update\FakesUpdateWorld;
use Tests\TestCase;

class ResetSystemPageTest extends TestCase
{
    use FakesUpdateWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.display_timezone' => 'America/Sao_Paulo', 'fluxie.allow_reset' => true]);
        $this->prepareUpdateSettings();
        Setting::put('company.name', 'Dimetal');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN, 'name' => 'Ana', 'email' => 'ana@x.test', 'password' => 'secret-123']);
    }

    /** @return MockInterface&ResetSystem */
    private function reset(?string $problem = null, int $starts = 0, string $actor = 'Ana <ana@x.test>'): MockInterface
    {
        return $this->mock(ResetSystem::class, function (MockInterface $mock) use ($problem, $starts, $actor): void {
            $mock->shouldReceive('problem')->andReturn($problem);
            $mock->shouldReceive('start')->with($actor)->times($starts);
        });
    }

    private function filled(User $admin, string $name = 'Dimetal', string $password = 'secret-123', bool $agree = true)
    {
        return Livewire::actingAs($admin)->test(System::class)
            ->call('askReset')
            ->set('resetAgree', $agree)
            ->set('resetName', $name)
            ->set('resetPassword', $password);
    }

    public function test_desligado_mostra_o_texto_sem_botao(): void
    {
        config(['fluxie.allow_reset' => false]);

        Livewire::actingAs($this->admin())->test(System::class)
            ->assertSee('Desligado nesta instalação')
            ->assertSee('FLUXIE_ALLOW_RESET=true')
            ->assertDontSee('wire:click="askReset"', false);
    }

    public function test_ligado_mostra_o_botao_e_a_confirmacao_com_os_avisos(): void
    {
        $this->reset();

        Livewire::actingAs($this->admin())->test(System::class)
            ->assertSee('wire:click="askReset"', false)
            ->call('askReset')
            ->assertSee('inclusive TODOS os backups')
            ->assertSee('gere um backup e baixe-o antes')
            ->assertSee(route('configuracoes.backups'), false);
    }

    public function test_zera_com_caixa_nome_e_senha_certos(): void
    {
        $this->reset(null, 1);

        $this->filled($this->admin())->call('resetSystem')->assertRedirect(route('restauracao'));
    }

    public function test_nome_com_maiusculas_e_espacos_e_aceito(): void
    {
        $this->reset(null, 1);

        $this->filled($this->admin(), '  dIMEtal ')->call('resetSystem')->assertRedirect(route('restauracao'));
    }

    public function test_empresa_sem_nome_pede_zerar(): void
    {
        Setting::put('company.name', '  ');
        config(['app.name' => '']);
        $this->reset(null, 1);

        $this->filled($this->admin(), 'zerar')->call('resetSystem')->assertRedirect(route('restauracao'));
    }

    public function test_recusa_sem_caixa(): void
    {
        $this->reset();

        $this->filled($this->admin(), agree: false)->call('resetSystem')->assertNoRedirect()->assertSee('Marque a caixa');
    }

    public function test_recusa_nome_errado(): void
    {
        $this->reset();

        $this->filled($this->admin(), 'Outra')->call('resetSystem')->assertNoRedirect()->assertSee('nome da empresa');
    }

    public function test_recusa_senha_errada(): void
    {
        $this->reset();

        $this->filled($this->admin(), password: 'errada')->call('resetSystem')->assertNoRedirect()->assertSee('Senha incorreta');
    }

    public function test_senha_errada_repetida_e_limitada(): void
    {
        $this->reset();
        $admin = $this->admin();
        $page = $this->filled($admin, password: 'errada');

        foreach (range(1, 5) as $ignored) {
            $page->call('resetSystem')->assertSee('Senha incorreta');
        }

        // Senha certa na 6ª: recusada pelo limite, sem nem conferir.
        $page->set('resetPassword', 'secret-123')->call('resetSystem')->assertNoRedirect()->assertSee('Muitas tentativas');
        RateLimiter::clear('reset-system:'.$admin->id);
    }

    public function test_recusa_com_variavel_desligada_mesmo_chamando_a_acao_direto(): void
    {
        // ResetSystem de verdade: a trava da variável é dele.
        $page = $this->filled($this->admin());
        config(['fluxie.allow_reset' => false]);

        $page->call('resetSystem')->assertNoRedirect()->assertSet('resetProblem', 'O zerar está desligado nesta instalação.');
        $this->assertFalse(Cache::has(ResetSystem::STARTED));
    }

    public function test_supervisor_recebe_403(): void
    {
        $this->reset();

        Livewire::actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->test(System::class)->assertForbidden();
    }

    public function test_problema_aparece_e_nada_comeca(): void
    {
        $this->reset('Há um backup manual em andamento.');

        $this->filled($this->admin())->assertSee('Há um backup manual em andamento.')->call('resetSystem')->assertNoRedirect();
    }

    public function test_dois_cliques_despacham_um_so(): void
    {
        $this->reset();
        $page = $this->filled($this->admin());
        $lock = Cache::lock('reset-system-dispatch', 120);
        $this->assertTrue($lock->get());

        $page->call('resetSystem')->assertNoRedirect();

        $lock->release();
    }

    public function test_ask_reset_com_a_variavel_desligada_nao_abre_o_painel(): void
    {
        $this->reset();
        config(['fluxie.allow_reset' => false]);

        Livewire::actingAs($this->admin())->test(System::class)->call('askReset')->assertSet('resetting', false)->assertSet('resetProblem', null);
    }

    public function test_segundo_clique_depois_de_comecar_nao_despacha_de_novo(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['backup.tmp_path' => sys_get_temp_dir().'/nao-existe-'.uniqid()]);
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array', 'session.driver' => 'array', 'cache.default' => 'array']);
        $page = $this->filled($this->admin());

        $page->call('resetSystem')->assertRedirect(route('restauracao'));
        $page->set('resetAgree', true)->set('resetName', 'Dimetal')->set('resetPassword', 'secret-123')->call('resetSystem');

        Queue::assertPushed(ResetSystemJob::class, 1);
    }
}
