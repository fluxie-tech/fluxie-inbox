<?php

namespace App\Actions\Inbox;

use App\Actions\Contacts\CreateContact;
use App\Models\Contact;
use App\Models\Message;
use App\Services\Inbox\SpecialContent;
use App\Support\PhoneFormat;

/** O cartão `$index` de uma mensagem de contato DESTE ticket (o id vem do navegador), já como contato cadastrado. */
final class ContactFromCard
{
    public function __construct(private readonly CreateContact $create) {}

    public function __invoke(int $ticketId, int $messageId, int $index): ?Contact
    {
        $message = Message::query()->where('ticket_id', $ticketId)->where('type', 'contact')->find($messageId);
        $card = $message === null ? null : (SpecialContent::contacts($message->body)[$index] ?? null);
        $phone = $card['phones'][0] ?? '';

        if ($card === null || PhoneFormat::toWaId($phone) === null) {
            return null;
        }

        return ($this->create)($phone, $card['name'])['contact'];
    }
}
