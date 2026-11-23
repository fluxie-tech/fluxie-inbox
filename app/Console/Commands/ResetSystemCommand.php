<?php

namespace App\Console\Commands;

use App\Services\Backup\Restore\RestoreRejected;
use App\Services\Reset\ResetSystem;
use Illuminate\Console\Command;
use Throwable;

/** Continua um zerar que falhou no meio: o sistema fica em manutenção, então o admin não chega ao botão. */
class ResetSystemCommand extends Command
{
    protected $signature = 'app:reset-system {--continue : Continua o zerar que falhou no meio}';

    protected $description = 'Continua o zerar do sistema que falhou no meio (sem --continue, não faz nada)';

    public function handle(ResetSystem $reset): int
    {
        if (! config('fluxie.allow_reset')) {
            $this->error('O zerar está desligado nesta instalação. Defina FLUXIE_ALLOW_RESET=true no Coolify e reinicie.');

            return self::FAILURE;
        }

        if (! $this->option('continue')) {
            $this->error('Para zerar, use Configurações > Sistema no app. Para continuar um zerar que falhou, use --continue.');

            return self::FAILURE;
        }

        if (! $reset->canContinue()) {
            $this->error('Nenhum zerar para continuar.');

            return self::FAILURE;
        }

        if (($problem = $reset->problem(continuing: true)) !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        // O volume dos arquivos locais só o `app` monta, e o job roda no `worker`: aqui o terminal é do `app`.
        $this->line($reset->wipeLocalFiles().' arquivos locais apagados.');

        try {
            $reset->run();
        } catch (RestoreRejected $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            // O detalhe já foi para o log pelo próprio ResetSystem; a mensagem do erro pode trazer dado do banco.
            $this->error('Erro inesperado ao continuar o zerar. Veja o log do app.');

            return self::FAILURE;
        }

        $this->info('Sistema zerado. Abra /instalar e use o código da aba Environment do Coolify.');

        return self::SUCCESS;
    }
}
