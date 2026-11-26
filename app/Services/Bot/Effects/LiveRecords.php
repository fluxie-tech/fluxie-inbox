<?php

namespace App\Services\Bot\Effects;

use App\Actions\Contacts\SaveContact;
use App\Actions\Inbox\CloseTicket;
use App\Enums\ClosedReason;
use App\Enums\NoteOrigin;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketNote;

/** O que o bot registra: campo do contato, nota, encerramento e o motivo do encaminhamento. */
final class LiveRecords
{
    public function __construct(private readonly Ticket $ticket) {}

    public function saveContactField(string $field, string $value): void
    {
        (new SaveContact)($this->ticket->contact()->firstOrFail(), [$field => $value]);
    }

    public function note(string $body): void
    {
        TicketNote::create(['ticket_id' => $this->ticket->id, 'origin' => NoteOrigin::BOT, 'body' => $body]);
    }

    public function close(): void
    {
        (new CloseTicket)($this->ticket, null, ClosedReason::BOT);
    }

    public function logHandoff(string $reason, ?Sector $sector, ?int $assignedUserId): void
    {
        TicketEvent::create(['ticket_id' => $this->ticket->id, 'type' => 'bot_handoff', 'payload' => ['reason' => $reason, 'sector_id' => $sector?->id, ...($assignedUserId !== null ? ['assigned_user_id' => $assignedUserId] : [])]]);
    }
}
