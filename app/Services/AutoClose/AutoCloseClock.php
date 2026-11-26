<?php

namespace App\Services\AutoClose;

use App\Enums\TicketStatus;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\Inbox\BusinessHours;
use App\Settings\AutoCloseSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * O relógio do encerramento automático: dado um chamado e um instante, decide avisar, encerrar ou nada. Só decide; quem
 * envia o aviso, grava a marca e encerra é a rotina que o chama.
 */
class AutoCloseClock
{
    public function __construct(private readonly BusinessHours $hours = new BusinessHours, private readonly LastCountingMessage $last = new LastCountingMessage) {}

    /** `$canMessage` é falso com o canal parado ou desativado: então não sai aviso nem despedida. */
    public function step(Ticket $ticket, CarbonInterface $now, bool $canMessage): AutoCloseStep
    {
        $last = $this->waitingOn($ticket);

        if ($last === null) {
            return AutoCloseStep::NONE;
        }

        $open = $this->hours->isOpen($now);
        $farewellWaits = $canMessage && AutoCloseSettings::farewellEnabled() && ! $open;
        $warnedAt = $ticket->auto_close_warned_at;

        if ($warnedAt !== null && $warnedAt->gt($last->sent_at)) {
            return $this->closeWhen($now >= $this->due($warnedAt, AutoCloseSettings::closeAfterWarnMinutes()), $farewellWaits);
        }

        $idle = $now >= $this->due($last->sent_at, AutoCloseSettings::idleMinutes());

        if ($canMessage && AutoCloseSettings::warnEnabled()) {
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
        $enters = AutoCloseSettings::enabled()
            && in_array($ticket->status, [TicketStatus::PENDENTE, TicketStatus::EM_ATENDIMENTO], true)
            && ! ($ticket->status === TicketStatus::PENDENTE && $ticket->botSession()->active()->whereNotNull('expires_at')->exists());

        $last = $enters ? $this->last->for($ticket) : null;

        return $last !== null && $this->last->isOurs($last) ? $last : null;
    }

    private function due(CarbonInterface $from, int $minutes): CarbonImmutable
    {
        return AutoCloseSettings::businessHoursOnly()
            ? $this->hours->addBusinessSeconds($from, $minutes * 60)
            : CarbonImmutable::instance($from)->addMinutes($minutes);
    }
}
