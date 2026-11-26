<?php

namespace App\Actions\Inbox;

use App\Enums\TicketStatus;
use App\Jobs\MarkTicketSeenJob;
use App\Models\Ticket;
use App\Models\User;

/** O atendente abriu o ticket na tela: marca como visto (menos o encerrado, que não tem o que ver). */
final class OpenTicketForAgent
{
    public function __construct(private readonly MarkTicketSeenByAgent $seen) {}

    public function __invoke(Ticket $ticket, User $by): void
    {
        if ($ticket->status !== TicketStatus::ENCERRADO) {
            MarkTicketSeenJob::dispatch($ticket->id);
            ($this->seen)($ticket, $by);
        }
    }
}
