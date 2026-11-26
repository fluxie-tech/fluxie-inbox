<?php

namespace App\Services\Inbox;

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;

/** A linha `messages` de uma mensagem que chegou do WhatsApp. */
class InboundRow
{
    public function create(Ticket $ticket, Contact $contact, IncomingMessage $incoming): Message
    {
        return Message::create([
            'ticket_id' => $ticket->id,
            'channel_id' => $ticket->channel_id,
            'contact_id' => $contact->id,
            'wa_message_id' => $incoming->waId,
            'direction' => $incoming->fromMe ? MessageDirection::OUT : MessageDirection::IN,
            'type' => $incoming->type,
            'body' => $incoming->body,
            'media_ref' => $incoming->media?->ref,
            'media_mime' => $incoming->media?->mime,
            'media_name' => $incoming->media?->name,
            'media_size' => $incoming->media?->size,
            'origin' => $incoming->fromMe ? MessageOrigin::FORA_DO_PAINEL : MessageOrigin::CONTATO,
            'sent_at' => $incoming->sentAt,
            'delivery_status' => $incoming->fromMe ? (DeliveryStatus::fromAck($incoming->ack) ?? DeliveryStatus::SENT) : null,
            ...$incoming->quoteColumns(),
        ]);
    }
}
