<?php

namespace Tests\Feature\Console;

use App\Services\Backup\Restore\RestoreRejected;
use App\Services\Reset\ResetSystem;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\TestCase;

class ResetSystemCommandTest extends TestCase
{
    private MockInterface $reset;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fluxie.allow_reset' => true]);
        $this->reset = $this->mock(ResetSystem::class);
    }

    public function test_sem_continue_recusa_e_aponta_o_botao(): void
    {
        $this->reset->shouldNotReceive('run');

        $this->artisan('app:reset-system')
            ->expectsOutputToContain('Configurações > Sistema')
            ->assertExitCode(1);
    }

    public function test_variavel_desligada_recusa(): void
    {
        config(['fluxie.allow_reset' => false]);
        Cache::forever(ResetSystem::STARTED, true);
        $this->reset->shouldNotReceive('run');

        $this->artisan('app:reset-system --continue')
            ->expectsOutputToContain('desligado')
            ->assertExitCode(1);
    }

    public function test_sem_zerar_comecado_recusa(): void
    {
        Cache::forget(ResetSystem::STARTED);
        $this->reset->shouldReceive('canContinue')->andReturn(false);
        $this->reset->shouldNotReceive('run');

        $this->artisan('app:reset-system --continue')
            ->expectsOutputToContain('Nenhum zerar para continuar')
            ->assertExitCode(1);
    }

    public function test_continua_e_termina(): void
    {
        $this->reset->shouldReceive('canContinue')->andReturn(true);
        $this->reset->shouldReceive('problem')->with(true)->andReturn(null);
        $this->reset->shouldReceive('run')->once();

        $this->artisan('app:reset-system --continue')->assertExitCode(0);
    }

    public function test_falha_no_run_sai_com_1_e_mostra_a_mensagem(): void
    {
        $this->reset->shouldReceive('canContinue')->andReturn(true);
        $this->reset->shouldReceive('problem')->with(true)->andReturn(null);
        $this->reset->shouldReceive('run')->once()->andThrow(new RestoreRejected('O pg_restore falhou (código 1).'));

        $this->artisan('app:reset-system --continue')
            ->expectsOutputToContain('O pg_restore falhou (código 1).')
            ->assertExitCode(1);
    }
}
