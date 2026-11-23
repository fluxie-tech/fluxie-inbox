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
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Zera a instalação (banco, bancos guardados, mídias, backups, filas, cache e sessões do WAHA) em manutenção e a devolve ao
 * /instalar. Cada passo é idempotente: o `--continue` do comando repete os passos depois de uma falha.
 */
class ResetSystem
{
    public const STEPS = ['reset-down', 'reset-waha', 'reset-queues', 'reset-media', 'reset-backups', 'reset-databases', 'reset-schema', 'reset-cache', 'reset-up'];

    /** Presente do começo até o `reset-cache`: é o que libera o `--continue`. */
    public const STARTED = 'reset:started';

    /** As sessões do WAHA dos canais, lidas antes de o banco ser zerado (`list<string>`). */
    public const SESSIONS = 'reset:waha-sessions';

    /** O mesmo lock da restauração: os dois nunca rodam juntos (a conexão de locks não é a do `Cache::flush()`). */
    public const LOCK = 'restore-database';

    private const COMMAND = 'php artisan app:reset-system --continue';

    public function __construct(
        private readonly Maintenance $maintenance,
        private readonly AdminDatabase $admin,
        private readonly RestoreProgress $progress,
        private readonly ManualBackupState $backupState,
    ) {}

    /** O que impede de zerar agora, ou `null`. `$continuing` ignora o "já está em manutenção" (o próprio zerar o causou) e o andamento. */
    public function problem(bool $continuing = false): ?string
    {
        $maintenance = $this->maintenance->problem();
        $ownMaintenance = $continuing && $maintenance === 'O sistema já está em manutenção.';

        return match (true) {
            ! config('fluxie.allow_reset') => 'O zerar está desligado nesta instalação.',
            $maintenance !== null && ! $ownMaintenance => $maintenance,
            ! $continuing && $this->progress->isRunning() => 'Há uma restauração ou um zerar em andamento.',
            SystemUpdate::open() !== null => 'Há uma atualização em andamento.',
            $this->backupState->isRunning() => 'Há um backup manual em andamento.',
            default => $this->wahaProblem(),
        };
    }

    public function start(string $actor): void
    {
        $sessions = $this->channelSessions();
        Cache::forever(self::SESSIONS, $sessions);
        Cache::forever(self::STARTED, true);
        $this->progress->begin('Zerando o sistema', self::STEPS, 'reset');
        Log::warning('Zerar o sistema pedido por '.$actor.' ('.count($sessions).' canais).');

        ResetSystemJob::dispatch();
    }

    public function canContinue(): bool
    {
        return Cache::has(self::STARTED);
    }

    /** @throws RestoreRejected|Throwable */
    public function run(): void
    {
        $lock = Cache::lock(self::LOCK, 1800);

        if (! $lock->get()) {
            throw new RestoreRejected('Já há uma restauração em andamento.');
        }

        try {
            $this->step('reset-down', function (): void {
                if (! app()->isDownForMaintenance()) {
                    $this->maintenance->enter();
                }
            });
            $this->step('reset-waha', $this->disconnectWaha(...));
            $this->step('reset-queues', function (): void {
                $this->maintenance->clearQueues();
                Sleep::for(30)->seconds();
            });
            $this->step('reset-media', fn () => $this->empty(Message::mediaDisk()));
            $this->step('reset-backups', fn () => $this->empty(Storage::disk(config()->string('backup.disk'))));
            $this->step('reset-databases', function (): void {
                foreach ([...$this->admin->stored(), ...$this->admin->staging()] as $database) {
                    $this->admin->drop($database->name);
                }
            });
            $this->step('reset-schema', fn () => $this->maintenance->fresh());
            $this->step('reset-cache', function (): void {
                $state = $this->progress->get();
                Cache::flush();
                $this->progress->replace($state);
            });
            $this->step('reset-up', function (): void {
                $this->maintenance->restartWorkers();
                $this->maintenance->leave();
            });
        } catch (Throwable $e) {
            $this->progress->fail(($e instanceof RestoreRejected ? $e->getMessage() : 'Erro inesperado ('.class_basename($e).'). Veja o log do app.').' Para continuar: '.self::COMMAND);
            Log::warning('Zerar o sistema falhou: '.$e->getMessage());
            report($e);

            throw $e;
        } finally {
            $lock->release();
        }

        $message = 'Sistema zerado. Abra /instalar e use o código da aba Environment do Coolify.';
        $this->progress->finish($message);
        Log::warning($message);
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

    private function disconnectWaha(): void
    {
        $sessions = Cache::get(self::SESSIONS, []);

        foreach (is_array($sessions) ? array_filter($sessions, is_string(...)) : [] as $name) {
            $client = $this->waha($name);
            $client->logout();
            $client->deleteSession();
        }
    }

    /** O cliente do WAHA exige um canal: um em memória, só com a sessão, basta. */
    private function waha(string $session): WahaClient
    {
        return new WahaClient((new Channel)->forceFill(['waha_session' => $session]));
    }

    /** Apaga tudo do disco, pelos diretórios de topo e pelos arquivos da raiz (no S3 o Flysystem apaga em lotes). */
    private function empty(Filesystem $disk): void
    {
        foreach ($disk->directories('') as $directory) {
            $disk->deleteDirectory($directory);
        }

        $disk->delete($disk->files(''));
    }
}
