<?php

namespace App\Livewire\Settings;

use App\Actions\Backup\DispatchRestore;
use App\Actions\Backup\RunManualBackup;
use App\Services\Backup\BackupOverview;
use App\Services\Backup\Restore\BackupReview;
use App\Services\Backup\Restore\InvalidDump;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Title('Backup do banco')]
class Backups extends Component
{
    use WithFileUploads;

    /** O arquivo enviado do computador (upload temporário do Livewire). */
    public mixed $upload = null;

    /** O que será restaurado: `bucket:<nome>` ou `upload:<ulid>`. Só o servidor define (a tela não pode forjar). */
    #[Locked]
    public ?string $source = null;

    /** O banco guardado a que se volta (`<db>_antes_...`), só definido por {@see self::chooseStored()}. */
    #[Locked]
    public ?string $target = null;

    /** @var array<string, string> o resumo mostrado na conferência */
    #[Locked]
    public array $info = [];

    public string $confirm = '';

    public ?string $problem = null;

    public function mount(): void
    {
        $this->authorize('admin');
    }

    /** Enfileira o mesmo backup da rotina diária, como manual (um dump pode levar minutos: não roda no request). */
    public function runNow(RunManualBackup $run): void
    {
        $this->authorize('admin');

        $this->problem = $run(Auth::user());
    }

    /** Abre a conferência de um backup da lista. */
    public function chooseBackup(string $name, BackupReview $review): void
    {
        $this->authorize('admin');
        $this->open('bucket:'.$name, $review);
    }

    /** O arquivo enviado do computador vai para o bucket (em `restauracao/`, fora da lista de backups) e abre a conferência. */
    public function updatedUpload(BackupReview $review): void
    {
        $this->authorize('admin');

        if (! config()->boolean('backup.restore.allow_upload')) {
            $this->upload = null;
            $this->problem = 'O envio de arquivo está desligado neste ambiente (BACKUP_RESTORE_UPLOAD=false).';

            return;
        }

        $this->validate([
            'upload' => ['required', 'file', 'extensions:dump', 'max:'.(config()->integer('backup.restore.upload_max_mb') * 1024)],
        ], [
            'upload.extensions' => 'O arquivo precisa ter a extensão .dump (backup do PostgreSQL).',
            'upload.max' => 'O arquivo passa do limite de '.config()->integer('backup.restore.upload_max_mb').' MB.',
        ]);

        $upload = $this->upload instanceof UploadedFile ? $this->upload : null;
        $this->upload = null;

        try {
            $source = $upload === null ? null : $review->fromUpload($upload);

            if ($source === null) {
                $this->problem = 'Não foi possível ler o arquivo enviado.';

                return;
            }

            $this->open($source, $review);
        } catch (InvalidDump $e) {
            $this->problem = $e->getMessage();
        }
    }

    /** Abre a conferência de um banco guardado para voltar a ele. */
    public function chooseStored(string $name, BackupReview $review): void
    {
        $this->authorize('admin');
        $this->cancelRestore($review);

        $info = $review->reviewStored($name);

        if ($info === null) {
            $this->problem = 'Esse banco guardado não existe mais.';

            return;
        }

        $this->target = $info['name'];
        $this->info = $info;
    }

    public function cancelRestore(BackupReview $review): void
    {
        $this->authorize('admin');

        if ($this->source !== null) {
            $review->discard($this->source);
        }

        $this->reset('source', 'target', 'info', 'confirm', 'problem');
    }

    public function restore(DispatchRestore $dispatch): void
    {
        $this->authorize('admin');

        if ($this->source === null) {
            $this->problem = 'Escolha um backup para restaurar.';

            return;
        }

        $this->start($dispatch, 'restore', $this->source);
    }

    public function switchBack(DispatchRestore $dispatch): void
    {
        $this->authorize('admin');

        if ($this->target === null) {
            $this->problem = 'Escolha um banco guardado para voltar a ele.';

            return;
        }

        $this->start($dispatch, 'switch', $this->target);
    }

    public function render(BackupOverview $overview): View
    {
        return view('livewire.settings.backups', $overview->forView());
    }

    /** Abre a conferência do arquivo; o inválido fica só como o aviso da tela. */
    private function open(string $source, BackupReview $review): void
    {
        $this->cancelRestore($review);

        try {
            $this->info = $review->review($source);
            $this->source = $source;
        } catch (InvalidDump $e) {
            $this->problem = $e->getMessage();
        }
    }

    /** Despacha a restauração: com a recusa, a tela mostra o motivo; sem ela, segue para o acompanhamento. */
    private function start(DispatchRestore $dispatch, string $mode, string $source): void
    {
        $this->problem = $dispatch($mode, $source, $this->info['name'] ?? $source, $this->confirm, Auth::user());

        if ($this->problem === null) {
            $this->redirect(route('restauracao'));
        }
    }
}
