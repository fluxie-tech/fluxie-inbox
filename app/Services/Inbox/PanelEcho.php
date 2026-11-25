<?php

namespace App\Services\Inbox;

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Events\TicketUpdated;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;

/** O eco `fromMe` de uma mensagem que o painel já gravou: casa com a linha pendente e a completa. */
class PanelEcho
{
    /** Janela para casar o eco `fromMe` com a mensagem que o painel gravou antes de enviar. */
    private const ECHO_WINDOW_SECONDS = 60;

    /** A resposta automática tem texto fixo e ninguém a digita no celular: casa por mais tempo, para o eco tardio não virar "fora do painel" e parar o relógio de SLA. */
    private const BOT_ECHO_WINDOW_SECONDS = 600;

    /**
     * A linha do painel que este eco `fromMe` representa. Anexo casa por tipo e legenda, não pelo tipo do arquivo: o
     * WhatsApp recomprime imagens e converte a voz, então o `mimetype` do eco não é o que enviamos.
     */
    public function match(Channel $channel, Contact $contact, IncomingMessage $incoming): ?Message
    {
        return Message::query()
            ->where('channel_id', $channel->id)
            ->where('contact_id', $contact->id)
            ->where('direction', MessageDirection::OUT->value)
            ->where(fn ($q) => $q
                ->where(fn ($panel) => $panel->where('origin', MessageOrigin::PAINEL->value)->where('updated_at', '>=', now()->subSeconds(self::ECHO_WINDOW_SECONDS)))
                ->orWhere(fn ($bot) => $bot->where('origin', MessageOrigin::BOT->value)->where('updated_at', '>=', now()->subSeconds(self::BOT_ECHO_WINDOW_SECONDS))))
            ->whereNull('wa_message_id')
            ->where(fn ($q) => $q->whereNull('delivery_status')
                // Só a falha "sem resposta" pode ter saído; as recusadas pelo WAHA não são engolidas pelo eco do celular.
                ->orWhere(fn ($failed) => $failed->where('delivery_status', DeliveryStatus::FAILED->value)->where('failure_code', SendFailure::UNKNOWN)))
            ->where('type', $incoming->type)
            ->where('body', $incoming->body)
            ->oldest('id')
            ->first();
    }

    /**
     * O eco da mensagem do painel chegou: a linha local ganha o id do WhatsApp em vez de virar uma segunda.
     */
    public function adopt(Message $pending, IncomingMessage $incoming): Message
    {
        $ticket = $pending->ticket()->firstOrFail();

        // O eco prova que saiu: uma linha que tinha falhado (ex.: timeout sem resposta) volta a "enviada".
        $pending->update([
            'wa_message_id' => $incoming->waId,
            'delivery_status' => $pending->delivery_status === DeliveryStatus::FAILED ? DeliveryStatus::SENT : $pending->delivery_status,
            'failure_reason' => null,
            'failure_code' => null,
        ]);
        // O eco da resposta automática não é trabalho humano (ver `SendTicketMessage::deliver`).
        if ($pending->origin !== MessageOrigin::BOT) {
            $ticket->recordOutbound($incoming->sentAt);
        }

        TicketUpdated::dispatch($ticket);

        return $pending;
    }
}
