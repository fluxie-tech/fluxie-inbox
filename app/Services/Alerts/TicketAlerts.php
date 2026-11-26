<?php

namespace App\Services\Alerts;

use App\Events\TicketQueued;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\SlaBreached;
use App\Notifications\SlaWarning;
use App\Notifications\TicketAssigned;
use Illuminate\Support\Facades\Notification;

/** O único lugar que transforma um fato do ticket (SLA estourou, atribuído, na fila) em alertas, usando {@see AlertRecipients}. */
final class TicketAlerts
{
    public function __construct(private readonly AlertRecipients $recipients) {}

    public function sla(Ticket $ticket, bool $breached): void
    {
        Notification::send($this->recipients->sla($ticket), $breached ? new SlaBreached($ticket) : new SlaWarning($ticket));
    }

    /** `$actor` nulo é a automação atribuindo: a pessoa é sempre avisada. */
    public function assigned(Ticket $ticket, User $to, ?User $actor): void
    {
        if ($actor === null || $to->id !== $actor->id) {
            $to->notify(new TicketAssigned($ticket));
        }
    }

    public function queued(Ticket $ticket, ?int $exceptUserId = null): void
    {
        $ids = $this->recipients->queue($ticket)->map(fn (User $user): int => $user->id)->reject(fn (int $id): bool => $id === $exceptUserId)->values()->all();

        if ($ids !== []) {
            TicketQueued::dispatch($ticket->id, $ids);
        }
    }
}
