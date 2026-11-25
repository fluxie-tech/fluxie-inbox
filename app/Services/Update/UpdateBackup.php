<?php

namespace App\Services\Update;

use App\Services\Backup\BackupKind;
use App\Services\Backup\DumpFailed;
use App\Services\Backup\RunBackup;
use RuntimeException;

/** O backup novo que a atualização faz antes de mexer no Coolify. */
final class UpdateBackup
{
    public function __construct(private readonly RunBackup $backup) {}

    /**
     * @return string o nome do arquivo gerado
     *
     * @throws RuntimeException
     */
    public function __invoke(): string
    {
        try {
            $file = ($this->backup)(BackupKind::Manual);
        } catch (DumpFailed $e) {
            throw new RuntimeException('O backup antes da atualização falhou: '.$e->getMessage());
        }

        return $file === null ? throw new RuntimeException('Já há um backup em andamento. Tente de novo quando ele terminar.') : $file->name;
    }
}
