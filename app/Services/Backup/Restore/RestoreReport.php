<?php

namespace App\Services\Backup\Restore;

use App\Enums\AuditAction;
use App\Notifications\BackupRestored;
use App\Services\Alerts\AlertRecipients;
use App\Services\Audit\Auditor;
use App\Services\Backup\DumpFailed;
use App\Settings\BackupStatus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/** O que a restauração conta no fim: o registro e o aviso aos admins, a mensagem pública e o log com a auditoria. */
class RestoreReport
{
    public function __construct(private readonly AlertRecipients $recipients) {}

    /** Grava o registro e avisa os admins (em melhor esforço) e devolve a mensagem final. */
    public function succeeded(RestoreRun $run): string
    {
        $run->bestEffort('O registro da restauração ou o aviso aos administradores não pôde ser gravado.', function () use ($run): void {
            BackupStatus::recordRestore($run->label, $run->request->actor);
            Notification::send($this->recipients->admins(), new BackupRestored);
        });

        $message = $run->request->mode === 'switch'
            ? 'O banco guardado voltou a ser o ativo. O banco que estava ativo ficou guardado.'
            : 'Restauração concluída. O banco anterior ficou guardado por '.config()->integer('backup.restore.keep_old_days').' dias.';
        if ($run->warnings !== []) {
            $message .= ' Atenção: '.implode(' ', $run->warnings);
        }

        return $message;
    }

    /**
     * O texto que a página de andamento (pública) mostra. Mensagens de processo (`pg_dump`, `pg_restore`) podem trazer host, usuário ou
     * linhas de tabela no stderr: ficam só no log.
     */
    public function publicMessage(Throwable $e): string
    {
        if ($e instanceof DumpFailed) {
            report($e);

            return 'O backup de segurança falhou, então nada foi trocado. O detalhe está no log do app.';
        }

        if ($e instanceof InvalidDump || $e instanceof RestoreRejected || $e instanceof SwapFailed) {
            return $e->getMessage();
        }

        report($e);

        return 'Erro inesperado na restauração ('.class_basename($e).'). Veja o log do app.';
    }

    public function log(RestoreRun $run, string $result, string $message): void
    {
        Log::notice('Restauração do banco', [
            'mode' => $run->request->mode,
            'source' => $run->request->source,
            'actor' => $run->request->actor,
            'result' => $result,
            'message' => $message,
        ]);

        // `$message` é o texto público que a página de andamento já mostra (sem a saída bruta do processo).
        $subject = $run->label !== '' ? $run->label : $run->request->source;
        Auditor::record($result === 'ok' ? AuditAction::RestoreSucceeded : AuditAction::RestoreFailed, null, $subject, ['mode' => $run->request->mode, 'message' => $message], $run->request->actor);
    }
}
