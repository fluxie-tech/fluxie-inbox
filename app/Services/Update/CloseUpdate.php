<?php

namespace App\Services\Update;

use App\Enums\AuditAction;
use App\Enums\UpdateStatus;
use App\Models\SystemUpdate;
use App\Models\User;
use App\Notifications\NewVersionAvailable;
use App\Services\Audit\Auditor;
use Illuminate\Notifications\DatabaseNotification;

/** Fecha a atualização com o estado final e a auditoria, uma vez só. */
final class CloseUpdate
{
    public function __invoke(SystemUpdate $update, UpdateStatus $status, string $message, AuditAction $action): bool
    {
        if (! $update->moveTo($status, $message)) {
            return false; // já fechada por outro processo: sem segunda auditoria
        }

        Auditor::record($action, $update->requested_by === null ? null : User::query()->find($update->requested_by), $update->to_version, ['message' => $message]);

        return true;
    }

    /** O aviso "Versão X disponível" deixa de valer: a X já está rodando. */
    public function dismissNotice(SystemUpdate $update): void
    {
        DatabaseNotification::query()->where('type', NewVersionAvailable::class)->whereNull('read_at')
            ->whereRaw("(data::jsonb ->> 'version') = ?", [(string) (Version::parse($update->to_version) ?? $update->to_version)])
            ->update(['read_at' => now()]);
    }
}
