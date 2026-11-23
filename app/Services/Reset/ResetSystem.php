<?php

namespace App\Services\Reset;

use App\Jobs\ResetSystemJob;
use App\Models\Channel;
use App\Models\Message;
use App\Models\SystemUpdate;
use App\Services\Backup\ManualBackupState;
use App\Services\Backup\Restore\AdminDatabase;
use App\Services\Backup\Restore\Maintenance;
use App\Services\Backup\Restore\RestoreProgress;
use App\Services\Backup\Restore\RestoreRejected;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaRequestException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

    /** As sessões do WAHA dos canais, lidas antes de o banco ser zerado (`list<string>`). */
    public const SESSIONS = 'reset:waha-sessions';

    /** O mesmo lock da restauração: os dois nunca rodam juntos (a conexão de locks não é a do `Cache::flush()`). */
    public const LOCK = 'restore-database';

    /** O mesmo lock do backup diário (`RunBackup`): ele não pode começar no meio do zerar. */
    private const BACKUP_LOCK = 'backup-database';

    /** Acima do `timeout` do job (840 s): o lock não pode soltar antes de o job acabar. */
    private const LOCK_SECONDS = 900;

    private const BACKUP_BUSY = 'Há um backup em andamento; espere ele terminar.';

    private const COMMAND = 'php artisan app:reset-system --continue';

    public function __construct(
        private readonly Maintenance $maintenance,
        private readonly AdminDatabase $admin,
        private readonly RestoreProgress $progress,
        private readonly ManualBackupState $backupState,
        private readonly SessionPurger $sessionPurger,
    ) {}

    /** O que impede de zerar agora, ou `null`. `$continuing` ignora o "já está em manutenção" (o próprio zerar o causou) e o andamento. */
    public function problem(bool $continuing = false): ?string
    {
        $maintenance = $this->maintenance->problem();
        $ownMaintenance = $continuing && $maintenance === Maintenance::ALREADY_DOWN;

        return match (true) {
            ! config('fluxie.allow_reset') => 'O zerar está desligado nesta instalação.',
            $maintenance !== null && ! $ownMaintenance => $maintenance,
            ! $continuing && $this->progress->isRunning() => 'Há uma restauração ou um zerar em andamento.',
            SystemUpdate::open() !== null => 'Há uma atualização em andamento.',
            $this->backupState->isRunning() => 'Há um backup manual em andamento.',
            $this->backupRunning() => self::BACKUP_BUSY,
            default => $this->wahaProblem(),
        };
    }

    public function start(string $actor): void
    {
        $sessions = $this->channelSessions();
        $files = $this->wipeLocalFiles();
        Cache::forever(self::SESSIONS, $sessions);
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
        $count = $this->empty(Storage::disk('local'));
        $tmp = config()->string('backup.tmp_path');

        if (is_dir($tmp)) {
            $count += count(File::allFiles($tmp, hidden: true));
            File::cleanDirectory($tmp);
        }

        return $count;
    }

    /** @throws RestoreRejected|Throwable */
    public function run(): void
    {
        $locks = $this->lock();
        $counts = ['mídias' => 0, 'backups' => 0];
        $flushed = false;
        $sessions = $this->sessionsToDisconnect();

        try {
            $this->step('reset-down', function (): void {
                if (! app()->isDownForMaintenance()) {
                    $this->maintenance->enter();
                }
            });
            $this->step('reset-waha', fn () => $this->disconnectWaha($sessions));
            $this->step('reset-queues', function (): void {
                $this->maintenance->clearQueues();
                Sleep::for(30)->seconds();
            });
            $this->step('reset-media', function () use (&$counts): void {
                $counts['mídias'] = $this->empty(Message::mediaDisk());
            });
            $this->step('reset-backups', function () use (&$counts): void {
                $counts['backups'] = $this->empty(Storage::disk(config()->string('backup.disk')));
            });
            $this->step('reset-databases', function (): void {
                foreach ([...$this->admin->stored(), ...$this->admin->staging()] as $database) {
                    $this->admin->drop($database->name);
                }
            });
            $this->step('reset-schema', fn () => $this->maintenance->fresh());
            $this->step('reset-cache', function () use (&$flushed): void {
                // As sessões primeiro: o flush leva o estado da manutenção (o sistema volta ao ar), e uma falha depois dele não se continua.
                $this->sessionPurger->purge();
                $state = $this->progress->get();
                Cache::flush();
                $flushed = true;
                $this->progress->replace($state);
            });
            $this->step('reset-up', function (): void {
                $this->maintenance->restartWorkers();
                $this->maintenance->leave();
            });
        } catch (Throwable $e) {
            $this->progress->fail($this->failureMessage($e, $flushed));
            Log::warning('Zerar o sistema falhou: '.$e->getMessage());
            report($e);

            throw $e;
        } finally {
            array_map(fn (Lock $lock) => $lock->release(), $locks);
        }

        $message = 'Sistema zerado. Abra /instalar e use o código da aba Environment do Coolify.';
        $this->progress->finish($message);
        Log::warning($message.' '.count($sessions).' canais, '.$counts['mídias'].' mídias e '.$counts['backups'].' backups apagados.');
    }

    /**
     * O lock da restauração e o do backup diário, juntos até o fim do zerar.
     *
     * @return list<Lock>
     *
     * @throws RestoreRejected
     */
    private function lock(): array
    {
        $restore = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        if (! $restore->get()) {
            $this->refuse('Já há uma restauração ou zerar em andamento. Espere alguns minutos e rode de novo: '.self::COMMAND);
        }

        $backup = Cache::lock(self::BACKUP_LOCK, self::LOCK_SECONDS);

        if (! $backup->get()) {
            $restore->release();
            $this->refuse(self::BACKUP_BUSY.' Espere alguns minutos e rode de novo: '.self::COMMAND);
        }

        return [$restore, $backup];
    }

    /** Depois do flush o sistema já voltou ao ar (o estado da manutenção ia no cache) e o `--continue` recusa: o que resta é reiniciar os workers. */
    private function failureMessage(Throwable $e, bool $flushed): string
    {
        if ($flushed) {
            return 'O sistema voltou ao ar, mas os workers podem não ter reiniciado: rode `php artisan queue:restart` no terminal do app.';
        }

        return ($e instanceof RestoreRejected ? $e->getMessage() : 'Erro inesperado ('.class_basename($e).'). Veja o log do app.').' Para continuar: '.self::COMMAND;
    }

    /** @throws RestoreRejected */
    private function refuse(string $message): never
    {
        $this->progress->fail($message);

        throw new RestoreRejected($message);
    }

    /** O backup diário em andamento: o lock é tomado e solto na hora, só para ver se está ocupado. */
    private function backupRunning(): bool
    {
        $lock = Cache::lock(self::BACKUP_LOCK, 5);

        if (! $lock->get()) {
            return true;
        }

        $lock->release();

        return false;
    }

    private function step(string $key, callable $work): void
    {
        $this->progress->running($key);
        $work();
        $this->progress->done($key);
    }

    /** @return list<string> */
    private function channelSessions(): array
    {
        return array_values(Channel::query()->whereNotNull('waha_session')->get()->map(fn (Channel $channel): string => $channel->waha_session)->all());
    }

    private function wahaProblem(): ?string
    {
        foreach ($this->channelSessions() as $name) {
            try {
                $this->waha($name)->session();
            } catch (ConnectionException) {
                return 'O WAHA não respondeu. Confira se ele está no ar antes de zerar.';
            } catch (WahaRequestException) {
                // O WAHA respondeu (404 de sessão que já não existe, por exemplo): está no ar.
            }
        }

        return null;
    }

    /**
     * As sessões guardadas no começo mais as dos canais de agora (um canal criado depois também sai). O banco pode estar sem a
     * tabela, num `migrate:fresh` interrompido: aí valem só as guardadas.
     *
     * @return list<string>
     */
    private function sessionsToDisconnect(): array
    {
        try {
            $current = $this->channelSessions();
        } catch (QueryException) {
            $current = [];
        }

        return array_values(array_unique([...$this->storedSessions(), ...$current]));
    }

    /** @return list<string> */
    private function storedSessions(): array
    {
        $sessions = Cache::get(self::SESSIONS, []);

        return is_array($sessions) ? array_values(array_filter($sessions, is_string(...))) : [];
    }

    /** @param  list<string>  $sessions */
    private function disconnectWaha(array $sessions): void
    {
        foreach ($sessions as $name) {
            $client = $this->waha($name);

            try {
                $client->logout();
            } catch (WahaRequestException $e) {
                // Sessão parada ou desconectada pode recusar o logout; repetir o mesmo erro travaria o `--continue`.
                report($e);
            }

            $client->deleteSession();
        }
    }

    /** O cliente do WAHA exige um canal: um em memória, só com a sessão, basta. */
    private function waha(string $session): WahaClient
    {
        return new WahaClient((new Channel)->forceFill(['waha_session' => $session]));
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
