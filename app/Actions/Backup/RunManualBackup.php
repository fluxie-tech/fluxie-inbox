<?php

namespace App\Actions\Backup;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Backup\ManualBackupState;
use App\Services\Backup\Restore\RestoreGate;
use Illuminate\Support\Facades\Artisan;

class RunManualBackup
{
    public function __construct(private readonly RestoreGate $gate, private readonly ManualBackupState $state) {}

    /** Enfileira o mesmo backup da rotina diária, como manual. Devolve o motivo da recusa, ou `null` se enfileirou. */
    public function __invoke(?User $by): ?string
    {
        $blocker = $this->gate->backupBlocker();

        if ($blocker !== null) {
            return $blocker;
        }

        $this->state->markStarted();

        // Fila `history`: o worker dela aguenta até 900 s; na `default` (120 s) um dump longo mataria o worker e as outras filas.
        Artisan::queue('app:backup-database', ['--manual' => true])->onQueue('history');
        Auditor::record(AuditAction::BackupManualRequested, $by);

        return null;
    }
}
