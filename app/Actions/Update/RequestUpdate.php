<?php

namespace App\Actions\Update;

use App\Enums\AuditAction;
use App\Jobs\ApplyUpdateJob;
use App\Models\SystemUpdate;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Update\InstalledVersion;
use App\Services\Update\UpdateGate;

class RequestUpdate
{
    /** O que o admin digita para confirmar, como a restauração do banco. */
    public const CONFIRM_WORD = 'ATUALIZAR';

    public function __construct(private readonly UpdateGate $gate, private readonly InstalledVersion $installed) {}

    /** Devolve o motivo da recusa, ou `null` se registrou e despachou a atualização. */
    public function __invoke(?User $by, string $typed): ?string
    {
        $target = $this->gate->target();

        $problem = $this->gate->blocker() ?? match (true) {
            $typed !== self::CONFIRM_WORD => 'Digite '.self::CONFIRM_WORD.' para confirmar.',
            default => $this->gate->busy(),
        };

        if ($problem !== null) {
            return $problem;
        }

        $update = SystemUpdate::start($by, $this->installed->current(), (string) $target);

        if ($update === null) {
            return 'Já há uma atualização em andamento.';
        }

        Auditor::record(AuditAction::UpdateRequested, $by, (string) $target);
        ApplyUpdateJob::dispatch($update->id);

        return null;
    }
}
