<?php

namespace App\Services\Backup\Restore;

use App\Services\Backup\BackupCatalog;
use App\Services\Backup\BackupKind;
use App\Services\Backup\RunBackup;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/** Os passos antes da troca do banco (nomes, arquivo, backup de segurança, banco novo) nos dois modos; uma falha aqui só apaga o banco novo. */
class PrepareRestore
{
    public function __construct(
        private readonly DumpInspector $inspector,
        private readonly RestoreSource $sources,
        private readonly AdminDatabase $admin,
        private readonly SideDatabase $side,
        private readonly RestoreProgress $progress,
        private readonly RunBackup $backup,
    ) {}

    /** @throws Throwable */
    public function restore(RestoreRun $run): void
    {
        $now = CarbonImmutable::now();
        $run->active = $this->admin->active();
        $run->incoming = AdminDatabase::stagingName($run->active, $now);
        $run->archive = $this->freeArchiveName($run->active, $now, $run->incoming);
        $this->assertNames($run);

        $this->progress->step('inspect', function () use ($run): void {
            $fetched = $this->sources->fetch($run->request->source);
            $run->path = $fetched['path'];
            $info = $this->inspector->inspect($fetched['path'], $fetched['name']);
            $run->label = $info->name;
            $run->from = $info->createdAt;
        });

        $this->progress->step('safety', function (): void {
            if (($this->backup)(BackupKind::Antes) === null) {
                throw new RestoreRejected('Há um backup em andamento. Tente de novo em instantes.');
            }
        });

        $this->progress->step('restore', function () use ($run): void {
            $run->staging = $run->incoming;
            $this->admin->create($run->incoming);
            $this->side->restore($run->incoming, (string) $run->path);
        });

        $this->progress->step('verify', fn () => $this->side->verify($run->incoming, BackupCatalog::versionIn($run->label)));
    }

    /** @throws Throwable */
    public function switchBack(RestoreRun $run): void
    {
        $stored = collect($this->admin->stored())->firstWhere('name', $run->request->source);

        if ($stored === null) {
            throw new RestoreRejected('Esse banco guardado não existe mais.');
        }

        $now = CarbonImmutable::now();
        $run->active = $this->admin->active();
        $run->incoming = $stored->name;
        $run->archive = $this->freeArchiveName($run->active, $now, $run->incoming);
        $run->label = $stored->name;
        $run->from = $stored->createdAt;
        $this->assertNames($run);
    }

    /**
     * O nome do banco guardado leva o minuto da troca; se já existe (duas restaurações no mesmo minuto, ou voltar logo depois de
     * restaurar, quando o banco a que se volta tem justamente o nome deste minuto), avança um minuto.
     */
    private function freeArchiveName(string $active, CarbonImmutable $at, string $incoming): string
    {
        $name = AdminDatabase::archiveName($active, $at);

        for ($tries = 0; $tries < 60 && ($name === $incoming || $this->admin->exists($name)); $tries++) {
            $at = $at->addMinute();
            $name = AdminDatabase::archiveName($active, $at);
        }

        return $name;
    }

    /** Os nomes dos bancos novos cabem nas regras (senão o erro só apareceria depois do download e do backup de segurança). */
    private function assertNames(RestoreRun $run): void
    {
        try {
            AdminDatabase::assertName($run->incoming);
            AdminDatabase::assertName($run->archive);
        } catch (InvalidArgumentException) {
            throw new RestoreRejected('O nome do banco ativo não permite criar o banco de restauração (só letras minúsculas, números e _, até 38 caracteres).');
        }
    }
}
