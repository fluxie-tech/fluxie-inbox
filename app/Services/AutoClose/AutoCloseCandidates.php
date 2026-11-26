<?php

namespace App\Services\AutoClose;

use App\Enums\MessageDirection;
use App\Enums\TicketStatus;
use App\Models\Message;
use App\Models\Ticket;
use App\Settings\AutoCloseSettings;
use Carbon\CarbonImmutable;

/**
 * Os chamados que a rotina do encerramento automático olha numa rodada. É um pré-filtro em SQL que nunca deixa de fora um
 * chamado vencido; quem decide de verdade é o `AutoCloseClock`.
 */
class AutoCloseCandidates
{
    public const LIMIT = 500;

    /** @return array<int, int> */
    public function ids(CarbonImmutable $now): array
    {
        $lastCounting = Message::query()
            ->select(['sent_at', 'direction'])
            ->whereColumn('messages.ticket_id', 'tickets.id')
            ->where('auto_reply', false)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(1);

        // Só entra quem tem a última mensagem que conta nossa. Com marca de aviso válida (posterior a ela) só importa o prazo Y
        // desde o aviso; sem marca, o prazo X desde a mensagem. Os minutos corridos nunca passam dos de expediente, então este
        // corte nunca exclui quem o relógio daria como vencido (o horário de atendimento só adia o vencimento).
        return Ticket::query()
            ->joinLateral($lastCounting, 'lm')
            ->whereIn('tickets.status', [TicketStatus::PENDENTE->value, TicketStatus::EM_ATENDIMENTO->value])
            ->where('lm.direction', MessageDirection::OUT->value)
            ->whereRaw(
                'case when tickets.auto_close_warned_at is not null and tickets.auto_close_warned_at > lm.sent_at then tickets.auto_close_warned_at < ? else lm.sent_at < ? end',
                [$now->subMinutes(AutoCloseSettings::closeAfterWarnMinutes()), $now->subMinutes(AutoCloseSettings::idleMinutes())],
            )
            // Pendente com a sessão do bot esperando resposta não entra (o relógio também recusa); sem isto ocuparia vagas à toa.
            ->where(fn ($query) => $query
                ->where('tickets.status', TicketStatus::EM_ATENDIMENTO->value)
                ->orWhereDoesntHave('botSession', fn ($session) => $session->active()->whereNotNull('expires_at')))
            ->orderBy('tickets.id')
            ->limit(self::LIMIT)
            ->get(['tickets.id'])
            ->map(fn (Ticket $ticket): int => $ticket->id)
            ->all();
    }
}
