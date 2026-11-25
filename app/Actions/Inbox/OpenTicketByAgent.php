<?php

namespace App\Actions\Inbox;

use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Jobs\SendTicketMessageJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Services\Inbox\SendTicketMessage;
use App\Services\Inbox\Signature;
use App\Services\Inbox\SyncContactPhoto;

/** O ticket e a primeira mensagem do atendente nascem juntos, o ticket já em atendimento e dele, sem SLA e sem bot. */
class OpenTicketByAgent
{
    public function __construct(private readonly SendTicketMessage $messages) {}

    /** Roda dentro da transação de quem chama. */
    public function __invoke(User $user, Contact $contact, Channel $channel, Sector $sector, string $text): Message
    {
        $ticket = Ticket::create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'sector_id' => $sector->id,
            'assigned_user_id' => $user->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'opened_at' => now(),
        ]);

        TicketEvent::create([
            'ticket_id' => $ticket->id,
            'type' => 'opened_by_agent',
            'actor_user_id' => $user->id,
            'payload' => ['channel_id' => $channel->id, 'sector_id' => $sector->id],
        ]);

        return $this->messages->create($ticket, $user, Signature::apply($text, $user, $sector));
    }

    /** Depois do commit: foto do contato, envio e atualização da tela. */
    public function announce(Message $message, Contact $contact, Channel $channel): void
    {
        app(SyncContactPhoto::class)->queueFor($contact, $channel);
        SendTicketMessageJob::dispatch($message->id);
        TicketUpdated::dispatch($message->ticket()->firstOrFail());
    }
}
