<?php

namespace App\Services\AutoClose;

use App\Services\Inbox\BusinessHours;
use App\Settings\AutoCloseSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Uma rodada do encerramento automático: avisa e encerra os chamados parados, até o limite de avisos e de despedidas por rodada.
 * O relógio (`AutoCloseClock`) decide; `AutoCloseDecision` grava a marca do aviso ou encerra; aqui estão o travamento e o envio.
 */
class AutoCloseRun
{
    public const MAX_WARNINGS = 5;

    public const MAX_FAREWELLS = 5;

    public function __construct(
        private readonly AutoCloseCandidates $candidates,
        private readonly LastCountingMessage $last,
        private readonly AutoCloseMessages $messages,
        private readonly AutoCloseDecision $decision,
    ) {}

    /** @return array{warned: int, closed: int} */
    public function __invoke(): array
    {
        $count = ['warned' => 0, 'closed' => 0];
        // Os ajustes, as faixas e os feriados são lidos uma vez para a rodada inteira, não a cada chamado.
        $config = AutoCloseSettings::snapshot();

        if (! $config->enabled) {
            return $count;
        }

        $now = now()->toImmutable();
        $clock = new AutoCloseClock((new BusinessHours)->snapshot(), $this->last, $config);
        $farewells = 0;

        foreach ($this->candidates->ids($now, $config) as $id) {
            // Não corre contra uma resposta que o job do bot está processando agora.
            $lock = Cache::lock('bot-ticket-'.$id, 120);

            if (! $lock->get()) {
                continue;
            }

            try {
                $done = ($this->decision)($id, $clock, $config, $now, $count['warned'] < self::MAX_WARNINGS, $farewells < self::MAX_FAREWELLS);
                $sent = $done !== null && $this->messages->send($done[0], $done[1], $config);
            } catch (Throwable $e) {
                // Um chamado com problema não pode travar os seguintes a cada minuto: registra e segue.
                Context::scope(fn () => report($e), ['auto_close_ticket_id' => $id]);

                continue;
            } finally {
                $lock->release();
            }

            if ($done !== null) {
                $warning = $done[0] === AutoCloseStep::WARN;
                $count[$warning ? 'warned' : 'closed']++;
                $farewells += ! $warning && $sent ? 1 : 0;
            }
        }

        return $count;
    }
}
