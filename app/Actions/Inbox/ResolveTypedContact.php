<?php

namespace App\Actions\Inbox;

use App\Actions\Contacts\CreateContact;
use App\Models\Channel;
use App\Models\Contact;
use App\Services\Inbox\ConversationNotStarted;
use App\Services\Inbox\WhatsAppNumber;
use App\Support\PhoneFormat;
use Illuminate\Validation\ValidationException;

/** Telefone digitado na "Nova conversa": confere no WhatsApp antes de cadastrar, para um número sem conta não virar contato. */
final class ResolveTypedContact
{
    public function __construct(private readonly WhatsAppNumber $number, private readonly CreateContact $createContact) {}

    /**
     * @return array{contact: ?Contact, phoneError: ?string} contato nulo = o erro do campo telefone vem pronto
     *
     * @throws ConversationNotStarted número sem WhatsApp ou consulta indisponível
     */
    public function __invoke(string $phone, string $name, Channel $channel): array
    {
        $waId = PhoneFormat::toWaId($phone);

        if ($waId === null) {
            return ['contact' => null, 'phoneError' => 'Telefone inválido: informe o DDD e o número.'];
        }

        $known = Contact::query()->whereIn('wa_id', PhoneFormat::variants($waId))->first();

        if ($known !== null) {
            return ['contact' => $known, 'phoneError' => null];
        }

        $this->number->assertExists($waId, $channel);

        try {
            return ['contact' => ($this->createContact)($phone, $name)['contact'], 'phoneError' => null];
        } catch (ValidationException $e) {
            return ['contact' => null, 'phoneError' => $e->validator->errors()->first('phone') ?: 'Telefone inválido.'];
        }
    }
}
