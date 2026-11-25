<?php

namespace App\Services\Reset;

use App\Jobs\ResetSystemJob;
use App\Services\Backup\Restore\AdminDatabase;
use App\Services\Backup\Restore\Maintenance;
use App\Services\Backup\Restore\RestoreProgress;
use App\Services\Backup\Restore\RestoreRejected;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Zera a instalação (banco, bancos guardados, mídias, backups, arquivos locais, filas, cache, sessões de login e sessões do WAHA) em manutenção e a devolve ao
 * /instalar. Cada passo é idempotente: o `--continue` do comando repete os passos depois de uma falha.
 */
class ResetSystem
{
    public const STEPS = ['reset-down', 'reset-waha', 'reset-queues', 'reset-media', 'reset-backups', 'reset-databases', 'reset-schema', 'reset-cache', 'reset-up'];

    /** Presente do começo até o `reset-cache`, por no máximo 24 h: é o que libera o `--continue`. */
    public const STARTED = 'reset:started';

    private const STARTED_HOURS = 24;

    public const SESSIONS = WahaSessions::SESSIONS;

    public const LOCK = ResetGuard::LOCK;

    public function __construct(
        private readonly Maintenance $maintenance,
        private readonly AdminDatabase $admin,
        private readonly RestoreProgress $progress,
        private readonly SessionPurger $sessionPurger,
        private readonly ResetGuard $guard,
        private readonly WahaSessions $waha,
        private readonly ResetFiles $files,
    ) {}

    /** O que impede de zerar agora, ou `null`. `$continuing` ignora o "já está em manutenção" (o próprio zerar o causou) e o andamento. */
    public function problem(bool $continuing = false): ?string
    {
        return $this->guard->problem($continuing);
    }

    public function start(string $actor): void
    {
        $sessions = $this->waha->current();
        $files = $this->wipeLocalFiles();
        Cache::forever(WahaSessions::SESSIONS, $sessions);
        Cache::put(self::STARTED, true, now()->addHours(self::STARTED_HOURS));
        $this->progress->begin('Zerando o sistema', self::STEPS, 'reset');
        Log::warning('Zerar o sistema pedido por '.$actor.' ('.count($sessions).' canais, '.$files.' arquivos locais apagados).');

        ResetSystemJob::dispatch();
    }

    public function canContinue(): bool
    {
        $state = $this->progress->get();

        // Depois do `reset-cache` o STARTED já foi embora; uma falha no `reset-up` ainda precisa poder continuar. Só em manutenção:
        // com o sistema no ar não há zerar parado no meio, e o `--continue` apagaria uma instalação funcionando.
        return app()->isDownForMaintenance() && (Cache::has(self::STARTED) || ($state['kind'] === 'reset' && $state['status'] === 'failed'));
    }

    /**
     * Esvazia o volume `app-storage`, que só o serviço `app` monta (por isso roda no request e no `--continue`, nunca no job):
     * o disco `local` (dumps enviados, anexos do compositor, bloco "Enviar mídia") e a pasta dos dumps órfãos. O `public` fica.
     *
     * @return int quantos arquivos foram apagados
     */
    public function wipeLocalFiles(): int
    {
        return $this->files->local();
    }

    /** @throws RestoreRejected|Throwable */
    public function run(): void
    {
        $locks = $this->guard->acquire();
        $counts = ['mídias' => 0, 'backups' => 0];
        $flushed = false;
        $sessions = $this->waha->toDisconnect();

        try {
            $this->progress->step('reset-down', function (): void {
                if (! app()->isDownForMaintenance()) {
                    $this->maintenance->enter();
                }
            });
            $this->progress->step('reset-waha', fn () => $this->waha->disconnect($sessions));
            $this->progress->step('reset-queues', function (): void {
                $this->maintenance->clearQueues();
                Sleep::for(30)->seconds();
            });
            $this->progress->step('reset-media', function () use (&$counts): void {
                $counts['mídias'] = $this->files->media();
            });
            $this->progress->step('reset-backups', function () use (&$counts): void {
                $counts['backups'] = $this->files->backups();
            });
            $this->progress->step('reset-databases', function (): void {
                foreach ([...$this->admin->stored(), ...$this->admin->staging()] as $database) {
                    $this->admin->drop($database->name);
                }
            });
            $this->progress->step('reset-schema', fn () => $this->maintenance->fresh());
            $this->progress->step('reset-cache', function () use (&$flushed): void {
                // As sessões primeiro: o flush leva o estado da manutenção (o sistema volta ao ar), e uma falha depois dele não se continua.
                $this->sessionPurger->purge();
                $state = $this->progress->get();
                Cache::flush();
                $flushed = true;
                $this->progress->replace($state);
            });
            $this->progress->step('reset-up', function (): void {
                $this->maintenance->restartWorkers();
                $this->maintenance->leave();
            });
        } catch (Throwable $e) {
            $this->progress->fail($this->failureMessage($e, $flushed));
            Log::warning('Zerar o sistema falhou: '.$e->getMessage());
            report($e);

            throw $e;
        } finally {
            $this->guard->release($locks);
        }

        $message = 'Sistema zerado. Abra /instalar e use o código da aba Environment do Coolify.';
        $this->progress->finish($message);
        Log::warning($message.' '.count($sessions).' canais, '.$counts['mídias'].' mídias e '.$counts['backups'].' backups apagados.');
    }

    /** Depois do flush o sistema já voltou ao ar (o estado da manutenção ia no cache) e o `--continue` recusa: o que resta é reiniciar os workers. */
    private function failureMessage(Throwable $e, bool $flushed): string
    {
        if ($flushed) {
            return 'O sistema voltou ao ar, mas os workers podem não ter reiniciado: rode `php artisan queue:restart` no terminal do app.';
        }

        return ($e instanceof RestoreRejected ? $e->getMessage() : 'Erro inesperado ('.class_basename($e).'). Veja o log do app.').' Para continuar: '.ResetGuard::COMMAND;
    }
}
