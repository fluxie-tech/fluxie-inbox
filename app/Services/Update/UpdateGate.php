<?php

namespace App\Services\Update;

use App\Services\Backup\ManualBackupState;
use App\Services\Backup\Restore\RestoreProgress;
use App\Settings\UpdateSettings;

class UpdateGate
{
    public function __construct(
        private readonly InstalledVersion $installed,
        private readonly RestoreProgress $restore,
        private readonly ManualBackupState $backup,
    ) {}

    /** Por que esta instalação não pode atualizar agora; a tela esconde o botão, mas a ação chamada direto também recusa. */
    public function blocker(): ?string
    {
        return match (true) {
            ! $this->hasService() => 'Esta instalação não é um Service do Coolify; atualize pelo painel.',
            ! $this->canUpdate() || $this->target() === null => 'Não há versão nova para instalar.',
            UpdateSettings::token() === null => 'Salve o token da API do Coolify antes de atualizar.',
            default => null,
        };
    }

    /** Só uma Release instalada, num Service do Coolify, tem o que atualizar; o `sha-…` do beta faria o pré-voo gravar lixo no Coolify. */
    public function canUpdate(): bool
    {
        return $this->installed->release() !== null && $this->hasService();
    }

    public function hasService(): bool
    {
        $uuid = config('fluxie.resource_uuid');

        return is_string($uuid) && $uuid !== '';
    }

    /** A versão disponível, se for maior que a instalada. */
    public function target(): ?Version
    {
        $installed = $this->installed->release();
        $latest = UpdateSettings::latest();
        $version = $latest === null ? null : Version::parse($latest['tag']);

        return $installed !== null && $version !== null && $version->greaterThan($installed) ? $version : null;
    }

    /** O que está rodando e não pode dividir o banco com a atualização. */
    public function busy(): ?string
    {
        return match (true) {
            $this->restore->isRunning() => 'Há uma restauração em andamento; espere ela terminar para atualizar.',
            $this->backup->isRunning() => 'Há um backup em andamento; espere ele terminar para atualizar.',
            default => null,
        };
    }
}
