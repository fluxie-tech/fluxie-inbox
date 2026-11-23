<?php

namespace Tests\Feature\Reset;

use App\Jobs\ResetSystemJob;
use App\Services\Backup\Restore\RestoreProgress;
use App\Services\Reset\ResetSystem;
use RuntimeException;
use Tests\TestCase;

class ResetSystemJobTest extends TestCase
{
    public function test_o_job_roda_na_fila_history_com_o_prazo_da_restauracao(): void
    {
        $job = new ResetSystemJob;

        $this->assertSame('history', $job->queue);
        $this->assertSame(840, $job->timeout);
        $this->assertSame(1, $job->tries);
        $this->assertTrue($job->failOnTimeout);
    }

    public function test_failed_marca_o_andamento_como_falho_com_o_comando(): void
    {
        app(RestoreProgress::class)->begin('Zerando o sistema', ResetSystem::STEPS, 'reset');

        (new ResetSystemJob)->failed(new RuntimeException('tempo'));

        $state = app(RestoreProgress::class)->get();
        $this->assertSame('failed', $state['status']);
        $this->assertStringContainsString('app:reset-system --continue', $state['message']);
    }
}
