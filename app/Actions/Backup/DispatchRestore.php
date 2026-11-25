<?php

namespace App\Actions\Backup;

use App\Enums\AuditAction;
use App\Jobs\RestoreDatabaseJob;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Backup\Restore\RestoreDatabase;
use App\Services\Backup\Restore\RestoreGate;
use App\Services\Backup\Restore\RestoreProgress;
use App\Services\Backup\Restore\RestoreRequest;
use Illuminate\Support\Facades\Cache;

class DispatchRestore
{
    /** Tempo do trinco de despacho: cobre um bloqueio lento (conexão ao banco de administração) sem soltar o trinco antes da hora. */
    public const LOCK_SECONDS = 120;

    public function __construct(private readonly RestoreGate $gate, private readonly RestoreProgress $progress) {}

    /**
     * Despacha a restauração (`restore`) ou a volta a um banco guardado (`switch`). Devolve o motivo da recusa, ou `null` se despachou.
     *
     * @param  string  $label  o nome mostrado na auditoria (o resumo da conferência), ou o próprio `$source`
     */
    public function __invoke(string $mode, string $source, string $label, string $typedConfirm, ?User $by): ?string
    {
        // Trinco curto: sem ele, dois pedidos simultâneos passam juntos pelo `isRunning()` antes de o primeiro marcar o andamento.
        $latch = Cache::lock('restore-dispatch', self::LOCK_SECONDS);

        if (! $latch->get()) {
            return 'Já há uma restauração em andamento.';
        }

        try {
            return $this->dispatch($mode, $source, $label, $typedConfirm, $by);
        } finally {
            $latch->release();
        }
    }

    private function dispatch(string $mode, string $source, string $label, string $typedConfirm, ?User $by): ?string
    {
        $blocker = $this->gate->restoreBlocker($typedConfirm);

        if ($blocker !== null) {
            return $blocker;
        }

        $switch = $mode === 'switch';
        $actor = $by === null ? 'desconhecido' : $by->name.' <'.$by->email.'>';

        // O pedido fica no banco de agora; o resultado (`RestoreDatabase::log`) fica no banco que estiver ativo depois da troca. Grava
        // antes do job: com a fila `sync` (ou um worker muito rápido) o job troca o banco e o pedido cairia depois do resultado.
        Auditor::record($switch ? AuditAction::RestoreSwitched : AuditAction::RestoreRequested, $by, $label);

        // O andamento já aparece na página de acompanhamento antes de o worker pegar o job.
        $this->progress->begin(
            $switch ? 'Voltando para o banco guardado' : 'Restaurando o banco',
            $switch ? RestoreDatabase::SWITCH_STEPS : RestoreDatabase::RESTORE_STEPS,
        );
        RestoreDatabaseJob::dispatch(new RestoreRequest($mode, $source, $actor));

        return null;
    }
}
