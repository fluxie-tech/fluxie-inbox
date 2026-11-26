<?php

namespace App\Services\Backup;

use App\Services\Backup\Restore\BackupReview;
use App\Services\Backup\Restore\RestoreProgress;
use App\Settings\BackupStatus;
use Throwable;

/** O que a tela de backup mostra: os arquivos do bucket, o último resultado, o andamento e os limites da restauração. */
class BackupOverview
{
    public function __construct(
        private readonly BackupCatalog $catalog,
        private readonly BackupReview $review,
        private readonly ManualBackupState $manual,
        private readonly RestoreProgress $progress,
    ) {}

    /** @return array<string, mixed> */
    public function forView(): array
    {
        $timezone = config()->string('app.display_timezone');
        $files = [];
        $unreadable = false;

        try {
            $files = $this->catalog->all();
        } catch (Throwable $e) {
            // Bucket inexistente ou chave errada: a tela é onde o admin diagnostica, então ela avisa em vez de dar erro 500.
            report($e);
            $unreadable = true;
        }

        $restoring = $this->progress->isRunning();
        $lastRestore = BackupStatus::lastRestore();

        return [
            'files' => $files,
            'unreadable' => $unreadable,
            'bytes' => array_sum(array_map(fn (BackupFile $file): int => $file->size, $files)),
            'lastSuccess' => BackupStatus::lastSuccessAt()?->setTimezone($timezone),
            'lastError' => BackupStatus::lastError(),
            'keep' => config('backup.keep'),
            'hour' => config()->string('backup.hour'),
            'stored' => $this->review->stored(),
            'manual' => $this->manual->get(),
            'restoring' => $restoring,
            'lastRestore' => $lastRestore === null ? null : [...$lastRestore, 'at' => $lastRestore['at']->setTimezone($timezone)],
            'confirmWord' => config()->string('backup.restore.confirm_word'),
            'uploadMaxMb' => config()->integer('backup.restore.upload_max_mb'),
            'allowUpload' => config()->boolean('backup.restore.allow_upload'),
        ];
    }
}
