<?php

namespace App\Actions\Inbox;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\ConversationNotStarted;
use App\Services\Inbox\ConversationPace;
use App\Services\Inbox\WhatsAppNumber;
use Illuminate\Support\Facades\DB;

/**
 * O atendente começa uma conversa: a única porta em que o ticket nasce de uma mensagem nossa. Toda recusa acontece antes de
 * criar qualquer linha; o ticket e a primeira mensagem nascem juntos, o ticket já em atendimento e do autor, sem SLA e sem bot.
 */
class StartConversation
{
    public const TEXT_LIMIT = 4096;

    private const SECTOR_REFUSED = 'Escolha um setor que receba tickets e que você atenda.';

    public function __construct(
        private readonly OpenTicketByAgent $open,
        private readonly ConversationPace $pace,
        private readonly WhatsAppNumber $number,
    ) {}

    /**
     * @return array{ticket: Ticket, created: bool} `created` falso quando já havia ticket aberto do contato no canal (nada é enviado)
     *
     * @throws ConversationNotStarted
     */
    public function __invoke(User $user, Contact $contact, Channel $channel, Sector $sector, string $text): array
    {
        $text = trim($text);
        $this->guard($user, $contact, $channel, $sector, $text);

        $open = $this->openTicket($contact, $channel);

        if ($open !== null) {
            return ['ticket' => $open, 'created' => false];
        }

        $this->number->assertExists((string) $contact->wa_id, $channel);
        $this->pace->reserve($contact, $channel);

        $made = DB::transaction(function () use ($user, $contact, $channel, $sector, $text): array {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['ticket:'.$contact->id.':'.$channel->id]);

            $existing = $this->openTicket($contact, $channel);

            if ($existing !== null) {
                return ['ticket' => $existing, 'message' => null];
            }

            // O setor veio do guard, antes da transação: pode ter sido apagado ou desativado desde então. Nada foi enviado, então o ritmo volta.
            if (Sector::lockForReceiving($sector->id) === null) {
                $this->pace->release($contact, $channel);

                throw new ConversationNotStarted(self::SECTOR_REFUSED);
            }

            return ($this->open)($user, $contact, $channel, $sector, $text);
        });

        if ($made['message'] instanceof Message) {
            $this->open->announce($made['ticket'], $made['message'], $contact, $channel);

            return ['ticket' => $made['ticket'], 'created' => true];
        }

        return ['ticket' => $made['ticket'], 'created' => false];
    }

    /** @throws ConversationNotStarted */
    private function guard(User $user, Contact $contact, Channel $channel, Sector $sector, string $text): void
    {
        $problem = match (true) {
            $text === '' => 'Escreva a primeira mensagem.',
            mb_strlen($text) > self::TEXT_LIMIT => 'A mensagem passa de '.self::TEXT_LIMIT.' caracteres.',
            ! $user->active => 'Seu usuário está inativo.',
            ! $channel->active || ! $channel->isConnected() => 'Este canal está desativado ou desconectado do WhatsApp.',
            ! str_ends_with((string) $contact->wa_id, '@c.us') => 'Este contato não tem telefone: só dá para conversar com quem tem número de WhatsApp.',
            ! $sector->status->receivesTickets() || ! $user->canHandleSector($sector->id) => self::SECTOR_REFUSED,
            default => null,
        };

        if ($problem !== null) {
            throw new ConversationNotStarted($problem);
        }
    }

    private function openTicket(Contact $contact, Channel $channel): ?Ticket
    {
        return Ticket::query()->open()->where('contact_id', $contact->id)->where('channel_id', $channel->id)->first();
    }
}
