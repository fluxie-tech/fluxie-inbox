<?php

namespace App\Services\Inbox;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\WahaEvent;
use Carbon\CarbonInterface;

/**
 * Transforma um evento `message`/`message.any` do WAHA em contato + ticket + mensagem.
 *
 * Idempotente: o mesmo `wa_message_id` no mesmo canal nunca gera segunda linha.
 */
class IngestMessage
{
    /** Até quanto atraso uma mensagem ao vivo ainda conta como cliente esperando (queda do número, reentrega do WhatsApp). */
    private const LATE_LIVE_SECONDS = 1800;

    public function __construct(
        private readonly ResolveContact $resolveContact,
        private readonly PanelEcho $echo,
        private readonly InboundTicket $tickets,
        private readonly StoreInbound $store,
    ) {}

    public function __invoke(WahaEvent $event): ?Message
    {
        $channel = $event->channel;
        $incoming = $channel === null ? null : IncomingMessage::fromEvent($event);

        if ($channel === null || $incoming === null) {
            return null;
        }

        $existing = $this->find($channel, $incoming->waId);

        if ($existing !== null) {
            return $existing;
        }

        $contact = ($this->resolveContact)($incoming->peer, $incoming->alternateJid, $incoming->pushName);
        $mode = IngestMode::of($event);
        $this->queueEnrichment($contact, $channel, $mode);

        // Já há ticket aberto do contato que começou antes desta mensagem: ela pertence a ele, não ao histórico.
        if ($mode === IngestMode::IMPORTED && $this->tickets->hasOpenBefore($channel, $contact, $incoming)) {
            $mode = IngestMode::RECOVERED;
        }

        $pending = $incoming->fromMe && in_array($incoming->type, ['text', 'media'], true) ? $this->echo->match($channel, $contact, $incoming) : null;

        if ($pending !== null) {
            return $this->echo->adopt($pending, $incoming);
        }

        $freshAt = $this->freshAt($event, $incoming, $mode);

        return ($this->store)($channel, $contact, $incoming, $mode, $freshAt, $event->received_at);
    }

    /**
     * A hora que o bot e a resposta automática chamam de "recente". Ao vivo e com até 30 min de atraso, é a hora em que a
     * mensagem chegou: o cliente que escreveu com o número offline e foi reentregue na volta está esperando agora. Mais velha
     * que isso, ou recuperada e importada, vale a hora de envio (é histórico reprocessado, não cliente esperando).
     */
    private function freshAt(WahaEvent $event, IncomingMessage $incoming, IngestMode $mode): CarbonInterface
    {
        $late = $event->received_at->diffInSeconds($incoming->sentAt, true) <= self::LATE_LIVE_SECONDS;

        return $mode === IngestMode::LIVE && $late ? $event->received_at : $incoming->sentAt;
    }

    /** Nome e foto do contato vêm do WAHA em jobs na fila; a foto só ao vivo, para importar milhares de conversas não virar milhares de chamadas de uma vez. */
    private function queueEnrichment(Contact $contact, Channel $channel, IngestMode $mode): void
    {
        app(FillContactName::class)->queueFor($contact, $channel);

        if ($mode === IngestMode::LIVE) {
            app(SyncContactPhoto::class)->queueFor($contact, $channel);
        }
    }

    private function find(Channel $channel, string $waId): ?Message
    {
        return Message::query()->where('channel_id', $channel->id)->where('wa_message_id', $waId)->first();
    }
}
