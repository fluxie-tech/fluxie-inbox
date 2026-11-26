<?php

namespace App\Services\AutoClose;

use App\Enums\TicketStatus;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\Inbox\BusinessHours;
use App\Settings\AutoCloseSettings;
use App\Settings\AutoCloseSnapshot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * O relógio do encerramento automático: dado um chamado e um instante, decide avisar, encerrar ou nada. Só decide; quem
 * envia o aviso, grava a marca e encerra é a rotina que o chama.
 */
class AutoCloseClock
{
    private readonly AutoCloseSnapshot $config;

    /** Sem `$config` lê os ajustes agora; a rodada passa o que já leu, para não voltar ao banco a cada chamado. */
    public function __construct(private readonly BusinessHours $hours = new BusinessHours, private readonly LastCountingMessage $last = new LastCountingMessage, ?AutoCloseSnapshot $config = null)
    {
        $this->config = $config ?? AutoCloseSettings::snapshot();
    }

    /** `$canMessage` é falso com o canal parado ou desativado: então não sai aviso nem despedida. */
    public function step(Ticket $ticket, CarbonInterface $now, bool $canMessage): AutoCloseStep
    {
        $last = $this->waitingOn($ticket);

        if ($last === null) {
            return AutoCloseStep::NONE;
        }

        $open = $this->hours->isOpen($now);
        $farewellWaits = $canMessage && $this->config->farewellEnabled && ! $open;
        $warnedAt = $ticket->auto_close_warned_at;

        // Com marca válida só vale o Y a partir do aviso, mesmo com o aviso desligado depois ou o canal parado (decisão do controlador).
        if ($warnedAt !== null && $warnedAt->gt($last->sent_at)) {
            return $this->closeWhen($now >= $this->due($warnedAt, $this->config->closeAfterWarnMinutes), $farewellWaits);
        }

        $idle = $now >= $this->due($last->sent_at, $this->config->idleMinutes);

        if ($canMessage && $this->config->warnEnabled) {
            return $idle && $open ? AutoCloseStep::WARN : AutoCloseStep::NONE;
        }

        return $this->closeWhen($idle, $farewellWaits);
    }

    /** Encerra se venceu, a não ser que a despedida ligada esteja esperando o horário de atendimento. */
    private function closeWhen(bool $due, bool $farewellWaits): AutoCloseStep
    {
        return $due && ! $farewellWaits ? AutoCloseStep::CLOSE : AutoCloseStep::NONE;
    }

    /** A última mensagem que conta, quando o chamado entra no encerramento automático e ela é nossa. */
    private function waitingOn(Ticket $ticket): ?Message
    {
        $enters = $this->config->enabled
            && in_array($ticket->status, [TicketStatus::PENDENTE, TicketStatus::EM_ATENDIMENTO], true)
            && ! ($ticket->status === TicketStatus::PENDENTE && $ticket->botSession()->active()->whereNotNull('expires_at')->exists());

        $last = $enters ? $this->last->for($ticket) : null;

        return $last !== null && $this->last->isOurs($last) ? $last : null;
    }

    private function due(CarbonInterface $from, int $minutes): CarbonImmutable
    {
        return $this->config->businessHoursOnly
            ? $this->hours->addBusinessSeconds($from, $minutes * 60)
            : CarbonImmutable::instance($from)->addMinutes($minutes);
    }
}
