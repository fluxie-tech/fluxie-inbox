<?php

namespace App\Jobs;

use App\Services\Backup\Restore\RestoreProgress;
use App\Services\Backup\Restore\RestoreRejected;
use App\Services\Reset\ResetSystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Zera o sistema na fila `history` (o worker dela aguenta até 900 s, como na restauração). */
class ResetSystemJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 840;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct()
    {
        $this->onQueue('history');
    }

    public function handle(ResetSystem $reset): void
    {
        try {
            $reset->run();
        } catch (RestoreRejected $e) {
            // Falha de passo ou lock ocupado; o andamento (da falha) já está gravado no `run()`.
            Log::warning('Zerar o sistema recusado: '.$e->getMessage());
        }
    }

    /** Job morto por tempo ou por erro: o andamento mostra o comando de continuar em vez de ficar "em andamento". */
    public function failed(?Throwable $e): void
    {
        $progress = app(RestoreProgress::class);

        // Uma falha de passo já gravou a mensagem específica; só a morte por tempo (ainda `running`) precisa da genérica.
        if ($progress->get()['status'] === 'failed') {
            return;
        }

        $e === null || report($e);
        $progress->fail('O zerar foi interrompido. Para continuar: php artisan app:reset-system --continue');
    }
}
