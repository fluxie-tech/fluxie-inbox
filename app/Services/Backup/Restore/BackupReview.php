<?php

namespace App\Services\Backup\Restore;

use Illuminate\Http\UploadedFile;
use Throwable;

/** A leitura de um backup (ou de um banco guardado) para a conferência que o admin faz antes de restaurar. */
class BackupReview
{
    public function __construct(
        private readonly RestoreSource $sources,
        private readonly DumpInspector $inspector,
        private readonly AdminDatabase $admin,
    ) {}

    /**
     * Lê o arquivo (cópia temporária, cabeçalho, lista de permissão) e devolve o resumo; o inválido é descartado (o enviado não fica
     * no bucket) e a exceção segue para a tela.
     *
     * @return array<string, string>
     *
     * @throws InvalidDump
     */
    public function review(string $source): array
    {
        $path = null;

        try {
            $fetched = $this->sources->fetch($source);
            $path = $fetched['path'];

            return $this->describe($this->inspector->inspect($path, $fetched['name']));
        } catch (InvalidDump $e) {
            $this->sources->discard($source);

            throw $e;
        } finally {
            $path === null || $this->sources->discardTemporary($path);
        }
    }

    /**
     * Guarda o arquivo enviado no bucket (em `restauracao/`, fora da lista de backups) e devolve a origem; `null` se não deu para ler.
     *
     * @throws InvalidDump
     */
    public function fromUpload(UploadedFile $upload): ?string
    {
        $path = $upload->getRealPath();

        return $path === false ? null : $this->sources->storeUpload($path);
    }

    /**
     * O resumo de um banco guardado para voltar a ele, ou `null` se ele não existe mais.
     *
     * @return array<string, string>|null
     */
    public function reviewStored(string $name): ?array
    {
        foreach ($this->stored() as $stored) {
            if ($stored->name === $name) {
                return [
                    'name' => $stored->name,
                    'created' => $stored->createdAt->format('d/m/Y H:i'),
                    'size' => $this->megabytes($stored->bytes),
                ];
            }
        }

        return null;
    }

    /** @return list<StoredDatabase> */
    public function stored(): array
    {
        try {
            return $this->admin->stored();
        } catch (Throwable) {
            return [];
        }
    }

    public function discard(string $source): void
    {
        $this->sources->discard($source);
    }

    /** @return array<string, string> */
    private function describe(DumpInfo $info): array
    {
        return [
            'name' => $info->name,
            'created' => $info->createdAt->setTimezone(config()->string('app.display_timezone'))->format('d/m/Y H:i'),
            'database' => $info->database,
            'server' => $info->serverVersion,
            'dump' => $info->dumpVersion,
            'compression' => $info->compression,
            'size' => $this->megabytes($info->size),
            'entries' => (string) $info->entries,
        ];
    }

    private function megabytes(int $bytes): string
    {
        return number_format($bytes / 1_000_000, 1, ',', '.').' MB';
    }
}
