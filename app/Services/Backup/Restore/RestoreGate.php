<?php

namespace App\Services\Backup\Restore;

use App\Models\SystemUpdate;
use App\Services\Backup\ManualBackupState;
use Throwable;

/** As guardas da tela de backup: o que impede o backup manual e o que impede a restauração agora (cada um com os próprios textos). */
class RestoreGate
{
    public const UPDATE_RUNNING = 'Há uma atualização do sistema em andamento; espere ela terminar.';

    public function __construct(
        private readonly RestoreProgress $progress,
        private readonly Maintenance $maintenance,
        private readonly AdminDatabase $admin,
        private readonly ManualBackupState $manual,
    ) {}

    public function backupBlocker(): ?string
    {
        $progress = $this->progress->get();

        if ($progress['status'] === 'running') {
            return $progress['kind'] === 'reset'
                ? 'Há um zerar do sistema em andamento; espere ele terminar para fazer um backup.'
                : 'Há uma restauração em andamento; espere ela terminar para fazer um backup.';
        }

        if (SystemUpdate::open() !== null) {
            return self::UPDATE_RUNNING;
        }

        return $this->manual->isRunning() ? 'Já há um backup em andamento.' : null;
    }

    public function restoreBlocker(string $typedConfirm): ?string
    {
        if ($typedConfirm !== config()->string('backup.restore.confirm_word')) {
            return 'Digite '.config()->string('backup.restore.confirm_word').' para confirmar.';
        }

        $progress = $this->progress->get();

        if ($progress['status'] === 'running') {
            return $progress['kind'] === 'reset' ? 'Há um zerar do sistema em andamento.' : 'Já há uma restauração em andamento.';
        }

        if (SystemUpdate::open() !== null) {
            return self::UPDATE_RUNNING;
        }

        $environment = $this->maintenance->problem();

        if ($environment !== null) {
            return $environment;
        }

        try {
            return $this->admin->canCreate() ? null : 'O usuário do banco não pode criar bancos (precisa ser superusuário ou ter CREATEDB).';
        } catch (Throwable $e) {
            report($e);

            return 'Não foi possível conectar ao banco de administração (`postgres`) com o usuário do app.';
        }
    }
}
