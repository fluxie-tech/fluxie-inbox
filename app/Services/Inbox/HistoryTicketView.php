<?php

namespace App\Services\Inbox;

use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Support\TicketTimeline;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/** O ticket aberto no histórico: o ticket (só se estiver no histórico do usuário), as últimas mensagens, os eventos e quem encerrou. */
final class HistoryTicketView
{
    /** A transcrição mostra as últimas mensagens, como o Inbox. */
    public const MESSAGE_LIMIT = 200;

    /**
     * @return array{ticket: ?Ticket, messages: Collection<int, Message>, hasMore: bool, events: SupportCollection<int, TicketEvent>, timeline: TicketTimeline, closer: ?string, limit: int}
     */
    public function for(User $user, ?int $ticketId): array
    {
        $ticket = $ticketId === null
            ? null
            : Ticket::query()->inHistoryOf($user)->with(['contact.tags', 'channel', 'assignee', 'sector', 'closeReason'])->find($ticketId);

        $messages = new Collection;
        $hasMore = false;
        $events = new Collection;
        $closer = null;

        if ($ticket !== null) {
            $latest = $ticket->messages()->with(['reactions', 'edits'])->orderByDesc('sent_at')->orderByDesc('id')->limit(self::MESSAGE_LIMIT + 1)->get();
            $hasMore = $latest->count() > self::MESSAGE_LIMIT;
            $messages = $latest->take(self::MESSAGE_LIMIT)->reverse()->values();
            $events = $ticket->events()->with('actor')->orderBy('created_at')->orderBy('id')->get();
            $closer = $events->last(fn (TicketEvent $event) => $event->type === 'closed')?->actor?->name;
        }

        return [
            'ticket' => $ticket,
            'messages' => $messages,
            'hasMore' => $hasMore,
            'events' => TicketTimeline::changes($events),
            'timeline' => new TicketTimeline($events),
            'closer' => $closer,
            'limit' => self::MESSAGE_LIMIT,
        ];
    }
}
