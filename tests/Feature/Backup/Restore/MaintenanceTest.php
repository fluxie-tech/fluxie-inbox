<?php

namespace Tests\Feature\Backup\Restore;

use App\Services\Backup\Restore\Maintenance;
use App\Services\Backup\Restore\RestoreRejected;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Um ambiente que serve para a restauração: manutenção, sessão e cache fora do banco.
        config([
            'app.maintenance.driver' => 'cache',
            'app.maintenance.store' => 'array',
            'session.driver' => 'array',
            'cache.default' => 'array',
        ]);
    }

    protected function tearDown(): void
    {
        // A trava é estática: não pode vazar para os outros testes do processo.
        DB::prohibitDestructiveCommands(false);

        parent::tearDown();
    }

    private function prohibited(string $command): bool
    {
        return (bool) (new ReflectionProperty($command, 'prohibitedFromRunning'))->getValue();
    }

    public function test_fresh_libera_os_comandos_destrutivos_so_durante_a_chamada_em_producao(): void
    {
        // Em produção o AppServiceProvider proíbe migrate:fresh e db:wipe; o zerar precisa deles.
        $this->app['env'] = 'production';
        DB::prohibitDestructiveCommands(true);

        Artisan::shouldReceive('call')->once()->with('migrate:fresh', ['--force' => true])->andReturnUsing(function (): int {
            $this->assertFalse($this->prohibited(FreshCommand::class));
            $this->assertFalse($this->prohibited(WipeCommand::class));

            return 0;
        });

        (new Maintenance)->fresh();

        $this->assertTrue($this->prohibited(FreshCommand::class));
        $this->assertTrue($this->prohibited(WipeCommand::class));
    }

    public function test_fresh_devolve_a_trava_mesmo_quando_falha(): void
    {
        $this->app['env'] = 'production';
        DB::prohibitDestructiveCommands(true);
        Artisan::shouldReceive('call')->once()->andThrow(new RuntimeException('falhou'));

        try {
            (new Maintenance)->fresh();
            $this->fail('A exceção devia subir.');
        } catch (RuntimeException) {
            $this->assertTrue($this->prohibited(FreshCommand::class));
            $this->assertTrue($this->prohibited(WipeCommand::class));
        }
    }

    public function test_ambiente_pronto_nao_tem_problema(): void
    {
        $this->assertNull((new Maintenance)->problem());
        $this->assertTrue((new Maintenance)->ready());
    }

    public function test_manutencao_por_arquivo_e_recusada(): void
    {
        config(['app.maintenance.driver' => 'file']);

        $this->assertStringContainsString('APP_MAINTENANCE_DRIVER=cache', (string) (new Maintenance)->problem());
        $this->assertFalse((new Maintenance)->ready());
    }

    public function test_manutencao_guardada_no_banco_e_recusada(): void
    {
        config(['app.maintenance.store' => 'database']);

        $this->assertStringContainsString('APP_MAINTENANCE_STORE=redis', (string) (new Maintenance)->problem());
    }

    public function test_sessao_no_banco_e_recusada(): void
    {
        config(['session.driver' => 'database']);

        $this->assertStringContainsString('SESSION_DRIVER=redis', (string) (new Maintenance)->problem());
    }

    public function test_cache_no_banco_e_recusado(): void
    {
        config(['cache.default' => 'database']);

        $this->assertStringContainsString('CACHE_STORE=redis', (string) (new Maintenance)->problem());
    }

    public function test_sistema_ja_em_manutencao_e_recusado(): void
    {
        $this->artisan('down')->assertSuccessful();

        $this->assertStringContainsString('já está em manutenção', (string) (new Maintenance)->problem());
    }

    public function test_fresh_chama_migrate_fresh_com_force(): void
    {
        Artisan::shouldReceive('call')->once()->with('migrate:fresh', ['--force' => true])->andReturn(0);

        (new Maintenance)->fresh();
    }

    public function test_fresh_que_falha_lanca_restore_rejected(): void
    {
        Artisan::shouldReceive('call')->once()->with('migrate:fresh', ['--force' => true])->andReturn(1);

        $this->expectException(RestoreRejected::class);
        $this->expectExceptionMessage('Não foi possível recriar o banco.');

        (new Maintenance)->fresh();
    }

    public function test_clear_queues_limpa_todas_as_filas_do_worker(): void
    {
        $filas = [
            ['redis', 'outbound'],
            ['redis', 'inbound'],
            ['redis', 'realtime'],
            ['redis', 'default'],
            ['redis', 'google'],
            ['redis', 'media'],
            ['redis_history', 'history'],
        ];

        foreach ($filas as [$conexao, $fila]) {
            Artisan::shouldReceive('call')->once()
                ->with('queue:clear', ['connection' => $conexao, '--queue' => $fila, '--force' => true])
                ->andReturn(0);
        }

        (new Maintenance)->clearQueues();
    }

    public function test_clear_queues_que_falha_lanca_restore_rejected(): void
    {
        Artisan::shouldReceive('call')->once()
            ->with('queue:clear', ['connection' => 'redis', '--queue' => 'outbound', '--force' => true])
            ->andReturn(1);

        $this->expectException(RestoreRejected::class);
        $this->expectExceptionMessage('Não foi possível esvaziar as filas.');

        (new Maintenance)->clearQueues();
    }
}
