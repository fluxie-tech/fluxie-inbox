<?php

namespace App\Services\Backup\Restore;

use App\Enums\ChannelStatus;
use App\Enums\UpdateStatus;
use App\Jobs\RecoverMessagesJob;
use App\Models\Channel;
use App\Models\SystemUpdate;
use Carbon\CarbonImmutable;
use Throwable;

/** A troca do banco em manutenção, o `migrate`, a volta ao ar e a recuperação das mensagens; e o desfazer da troca. */
class Cutover
{
    public function __construct(
        private readonly AdminDatabase $admin,
        private readonly Maintenance $maintenance,
        private readonly RestoreProgress $progress,
    ) {}

    /** @throws Throwable */
    public function __invoke(RestoreRun $run): void
    {
        $this->assertWithinDeadline($run);

        $this->progress->step('swap', function () use ($run): void {
            $this->maintenance->enter();
            $run->inMaintenance = true;
            $this->admin->swap($run->active, $run->incoming, $run->archive);
            $run->swapped = true;
        });

        $this->progress->step('migrate', function () use ($run): void {
            $this->maintenance->migrate();
            $run->bestEffort('Não foi possível encerrar a atualização do sistema que veio aberta no banco restaurado.', fn () => $this->closeOpenUpdates());
        });

        $this->progress->step('resume', function () use ($run): void {
            $this->maintenance->restartWorkers();
            $this->maintenance->leave();
            $run->inMaintenance = false;
            // Daqui o sistema grava no banco novo: uma falha nos passos seguintes não pode desfazer a troca.
            $run->swapped = false;
            $run->committed = true;
        });

        $this->progress->step('recover', function () use ($run): void {
            $run->bestEffort('A recuperação das mensagens do WhatsApp falhou; use a recuperação manual em cada canal.', fn () => $this->recoverMessages($run->from ?? CarbonImmutable::now()));
        });
    }

    /** Depois da troca, volta ao banco anterior; apaga o banco novo criado para esta restauração. */
    public function undo(RestoreRun $run, string $message): string
    {
        if ($run->swapped) {
            $this->admin->swap($run->active, $run->archive, $run->incoming);
            $run->swapped = false;
            $message .= ' A troca foi desfeita e o banco anterior continua ativo.';
        }

        if ($run->staging !== null && $this->admin->exists($run->staging)) {
            $this->admin->drop($run->staging);
        }

        return $message;
    }

    /** @throws RestoreRejected */
    private function assertWithinDeadline(RestoreRun $run): void
    {
        $elapsed = CarbonImmutable::now()->getTimestamp() - $run->startedAt->getTimestamp();

        if ($elapsed > config()->integer('backup.restore.cutover_deadline')) {
            throw new RestoreRejected('A restauração passou do tempo seguro antes da troca e foi cancelada; o banco atual continua ativo. Tente de novo ou use o roteiro manual para bancos muito grandes.');
        }
    }

    /**
     * O backup feito pelo botão de atualizar leva a linha da atualização aberta; no banco novo ela travaria backup e restauração e o
     * vigia mandaria um alerta falso. Fecha sem alerta nem auditoria de atualização (a restauração já é auditada).
     */
    private function closeOpenUpdates(): void
    {
        SystemUpdate::query()->whereIn('status', array_column(UpdateStatus::openCases(), 'value'))->update([
            'status' => UpdateStatus::Falhou->value,
            'message' => 'Fechada pela restauração do banco.',
            'step_at' => now(),
            'finished_at' => now(),
            'notified_at' => now(),
        ]);
    }

    /** Um `RecoverMessagesJob` por canal ativo e conectado, do backup (no máximo `recover_hours` atrás) até agora. */
    private function recoverMessages(CarbonImmutable $backupAt): void
    {
        $now = CarbonImmutable::now();
        $floor = $now->subHours(config()->integer('backup.restore.recover_hours'));
        $from = $backupAt->greaterThan($floor) ? $backupAt : $floor;

        Channel::query()->where('active', true)->where('status', ChannelStatus::WORKING->value)->get()
            ->each(fn (Channel $channel) => RecoverMessagesJob::dispatch($channel->id, $from, $now, false));
    }
}
