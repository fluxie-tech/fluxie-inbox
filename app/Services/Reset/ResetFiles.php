<?php

namespace App\Services\Reset;

use App\Models\Message;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/** Os arquivos que o zerar apaga: locais (no request), mídias e backups (no job). */
class ResetFiles
{
    /**
     * Esvazia o volume `app-storage`, que só o serviço `app` monta (por isso roda no request e no `--continue`, nunca no job):
     * o disco `local` (dumps enviados, anexos do compositor, bloco "Enviar mídia") e a pasta dos dumps órfãos. O `public` fica.
     *
     * @return int quantos arquivos foram apagados
     */
    public function local(): int
    {
        $count = $this->empty(Storage::disk('local'));
        $tmp = config()->string('backup.tmp_path');

        if (is_dir($tmp)) {
            $count += count(File::allFiles($tmp, hidden: true));
            File::cleanDirectory($tmp);
        }

        return $count;
    }

    public function media(): int
    {
        return $this->empty(Message::mediaDisk());
    }

    public function backups(): int
    {
        return $this->empty(Storage::disk(config()->string('backup.disk')));
    }

    /** Apaga tudo do disco, pelos diretórios de topo e pelos arquivos da raiz (no S3 o Flysystem apaga em lotes). */
    private function empty(Filesystem $disk): int
    {
        $count = count($disk->allFiles());

        foreach ($disk->directories('') as $directory) {
            $disk->deleteDirectory($directory);
        }

        $disk->delete($disk->files(''));

        return $count;
    }
}
