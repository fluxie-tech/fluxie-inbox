<?php

namespace App\Services\Inbox;

use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/** O que a conversa do ticket mostra: as últimas mensagens e as notas internas do mesmo período. */
final class TranscriptQuery
{
    private const LIMIT = 200;

    /**
     * Ticket que o usuário não pode ver (ou que sumiu) não devolve nada.
     *
     * @return array{messages: Collection<int, Message>, notes: Collection<int, TicketNote>}
     */
    public function for(User $user, int $ticketId): array
    {
        // O filho não confia no pai: confere de novo se o usuário pode ver este ticket.
        $ticket = Ticket::query()->readableBy($user)->find($ticketId);

        if ($ticket === null) {
            return ['messages' => new Collection, 'notes' => new Collection];
        }

        $messages = $ticket->messages()->with(['reactions', 'edits', 'ticket.channel', 'contact'])->orderByDesc('sent_at')->orderByDesc('id')->limit(self::LIMIT)->get()->reverse()->values();

        // Só as notas desde a mensagem mais antiga da página, para a conversa não ganhar notas soltas do passado.
        $oldest = $messages->first()?->sent_at;
        $notes = TicketNote::query()->where('ticket_id', $ticket->id)->with('author')
            ->when($oldest !== null, fn ($query) => $query->where('created_at', '>=', $oldest))
            ->orderBy('created_at')->limit(self::LIMIT)->get();

        return ['messages' => $messages, 'notes' => $notes];
    }
}
