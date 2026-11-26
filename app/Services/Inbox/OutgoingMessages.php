<?php

namespace App\Services\Inbox;

use App\Actions\Inbox\MarkTicketSeenByAgent;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Events\MessageCreated;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;

/**
 * Grava a linha de saída no clique, para o atendente ver a própria mensagem na hora e para o eco `fromMe` que pode chegar
 * antes da resposta HTTP encontrar a mensagem pendente em vez de gravar uma segunda. Não fala com o WAHA: o envio é de
 * `SendTicketMessage::deliver`.
 */
class OutgoingMessages
{
    /** @param  Message|null  $quoted  a mensagem que esta responde (o WhatsApp a mostra como citação) */
    public function create(Ticket $ticket, ?User $author, string $text, MessageOrigin $origin = MessageOrigin::PAINEL, ?Message $quoted = null, bool $autoReply = false): Message
    {
        return $this->record($ticket, $author, 'text', $text, $origin, self::quoteColumns($quoted), $autoReply);
    }

    /**
     * As colunas da citação de uma mensagem que sai.
     *
     * @return array<string, mixed>
     */
    public static function quoteColumns(?Message $quoted): array
    {
        return $quoted === null ? [] : ['quoted_wa_message_id' => $quoted->wa_message_id, 'quoted_snapshot' => Quote::snapshot($quoted)];
    }

    /**
     * Grava a linha de saída (texto ou anexo) e avisa a tela. O envio em si é de `SendTicketMessage::deliver`.
     *
     * @param  array<string, mixed>  $extra  colunas de mídia (`media_mime`, `media_name`, `media_size`, `local_path`)
     */
    public function record(Ticket $ticket, ?User $author, string $type, string $body, MessageOrigin $origin = MessageOrigin::PAINEL, array $extra = [], bool $autoReply = false): Message
    {
        $message = Message::create([
            'ticket_id' => $ticket->id,
            'channel_id' => $ticket->channel_id,
            'contact_id' => $ticket->contact_id,
            'direction' => MessageDirection::OUT,
            'type' => $type,
            'body' => $body,
            'origin' => $origin,
            'author_user_id' => $author?->id,
            'sent_at' => now(),
            'auto_reply' => $autoReply,
            ...$extra,
        ]);

        MessageCreated::dispatch($message);

        // Quem responde pelo painel viu a conversa: zera as não lidas (só se for o responsável).
        if ($author !== null && $origin === MessageOrigin::PAINEL) {
            app(MarkTicketSeenByAgent::class)($ticket, $author);
        }

        return $message;
    }
}
