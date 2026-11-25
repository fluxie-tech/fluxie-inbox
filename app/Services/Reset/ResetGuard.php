<?php

namespace App\Services\Reset;

use App\Models\SystemUpdate;
use App\Services\Backup\ManualBackupState;
use App\Services\Backup\Restore\Maintenance;
use App\Services\Backup\Restore\RestoreProgress;
use App\Services\Backup\Restore\RestoreRejected;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/** O que impede de zerar (bloqueios) e os dois locks que o zerar segura do começo ao fim. */
class ResetGuard
{
    /** O mesmo lock da restauração: os dois nunca rodam juntos (a conexão de locks não é a do `Cache::flush()`). */
    public const LOCK = 'restore-database';

    public const COMMAND = 'php artisan app:reset-system --continue';

    /** O mesmo lock do backup diário (`RunBackup`): ele não pode começar no meio do zerar. */
    private const BACKUP_LOCK = 'backup-database';

    /** Acima do `timeout` do job (840 s): o lock não pode soltar antes de o job acabar. */
    private const LOCK_SECONDS = 900;

    private const BACKUP_BUSY = 'Há um backup em andamento; espere ele terminar.';

    public function __construct(
        private readonly Maintenance $maintenance,
        private readonly RestoreProgress $progress,
        private readonly ManualBackupState $backupState,
        private readonly WahaSessions $waha,
    ) {}

    /** O que impede de zerar agora, ou `null`. `$continuing` ignora o "já está em manutenção" (o próprio zerar o causou) e o andamento. */
    public function problem(bool $continuing = false): ?string
    {
        $maintenance = $this->maintenance->problem();
        $ownMaintenance = $continuing && $maintenance === Maintenance::ALREADY_DOWN;

        return match (true) {
            ! config('fluxie.allow_reset') => 'O zerar está desligado nesta instalação.',
            $maintenance !== null && ! $ownMaintenance => $maintenance,
            ! $continuing && $this->progress->isRunning() => 'Há uma restauração ou um zerar em andamento.',
            SystemUpdate::open() !== null => 'Há uma atualização em andamento.',
            $this->backupState->isRunning() => 'Há um backup manual em andamento.',
            $this->backupRunning() => self::BACKUP_BUSY,
            default => $this->waha->problem(),
        };
    }

    /**
     * O lock da restauração e o do backup diário, juntos até o fim do zerar.
     *
     * @return list<Lock>
     *
     * @throws RestoreRejected
     */
    public function acquire(): array
    {
        $restore = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        if (! $restore->get()) {
            $this->refuse('Já há uma restauração ou zerar em andamento. Espere alguns minutos e rode de novo: '.self::COMMAND);
        }

        $backup = Cache::lock(self::BACKUP_LOCK, self::LOCK_SECONDS);

        if (! $backup->get()) {
            $restore->release();
            $this->refuse(self::BACKUP_BUSY.' Espere alguns minutos e rode de novo: '.self::COMMAND);
        }

        return [$restore, $backup];
    }

    /** @param  list<Lock>  $locks */
    public function release(array $locks): void
    {
        array_map(fn (Lock $lock) => $lock->release(), $locks);
    }

    /** @throws RestoreRejected */
    private function refuse(string $message): never
    {
        $this->progress->fail($message);

        throw new RestoreRejected($message);
    }

    /** O backup diário em andamento: o lock é tomado e solto na hora, só para ver se está ocupado. */
    private function backupRunning(): bool
    {
        $lock = Cache::lock(self::BACKUP_LOCK, 5);

        if (! $lock->get()) {
            return true;
        }

        $lock->release();

        return false;
    }
}
