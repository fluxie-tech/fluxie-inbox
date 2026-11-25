<?php

namespace App\Services\Inbox;

use App\Enums\ChannelStatus;
use App\Enums\DeliveryStatus;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaMedia;
use Throwable;

/**
 * Envia um texto do painel para o cliente do ticket, em dois passos.
 *
 * `OutgoingMessages::create` grava a linha no clique, para o atendente ver a própria mensagem na hora e para o eco `fromMe`
 * que pode chegar antes da resposta HTTP encontrar a mensagem pendente em vez de gravar uma segunda. `deliver` fala com o WAHA
 * e roda na fila de saída. Permissão é da Policy.
 */
class SendTicketMessage
{
    public function __construct(
        private readonly Presence $presence,
        private readonly OutgoingMessages $outgoing,
        private readonly OutboundStatus $status,
    ) {}

    public function __invoke(Ticket $ticket, User $author, string $text): Message
    {
        $message = $this->outgoing->create($ticket, $author, $text);
        $this->deliver($message);

        return $message;
    }

    public function deliver(Message $message): void
    {
        $ticket = $message->ticket()->firstOrFail();
        $contact = $ticket->contact()->firstOrFail();
        $channel = $ticket->channel()->firstOrFail();

        // Sessão fora de WORKING, ou mensagem mais antiga ainda esperando (a ordem da conversa não se perde): fica na fila
        // e a caixa de saída envia quando a conexão voltar.
        if ($channel->status !== ChannelStatus::WORKING || $this->waitingAhead($message)) {
            $this->status->park($message);

            return;
        }

        $file = $message->type === 'media' ? $this->readAttachment($message) : null;

        if ($message->type === 'media' && $file === null) {
            $this->status->fileGone($message);

            return;
        }

        // Começa a janela em que o eco `fromMe` ainda pode casar com esta linha.
        $message->touch();

        $client = new WahaClient($channel);

        // Bot e humano seguem o mesmo ritmo do WhatsApp (visto e "digitando"); o bot com teto menor e visto uma vez por rodada.
        $isVoice = $file !== null && AttachmentRules::kindOfStored((string) $message->media_mime) === 'audio';
        $this->presence->before($client, $ticket, $contact, $message->body, ! $isVoice, $message->origin);

        try {
            $response = $this->transmit($client, $message, $contact->wa_id ?? (string) $contact->lid, $file);
        } catch (Throwable $e) {
            $this->status->failed($message, $e);

            return;
        }

        $this->presence->scheduleOffline($channel);
        $this->status->sent($message, $ticket, $response);
    }

    /**
     * @return array<string, mixed> a resposta do WAHA ao envio
     */
    private function transmit(WahaClient $client, Message $message, string $chatId, ?string $file): array
    {
        $replyTo = $this->replyTo($message);

        if ($file === null) {
            return $client->sendText($chatId, $message->body, $replyTo);
        }

        return (new WahaMedia($client))->send(
            AttachmentRules::kindOfStored((string) $message->media_mime),
            $chatId,
            (string) $message->media_mime,
            (string) ($message->media_name ?? 'arquivo'),
            base64_encode($file),
            $message->body,
            $replyTo,
        );
    }

    /** O id completo da mensagem que esta responde, ou nulo quando não responde a nenhuma (ou ela já não existe). */
    private function replyTo(Message $message): ?string
    {
        if ($message->quoted_wa_message_id === null) {
            return null;
        }

        return Message::query()->where('channel_id', $message->channel_id)->where('wa_message_id', $message->quoted_wa_message_id)->first()?->fullWaId();
    }

    /** Os bytes do anexo na cópia local, ou `null` quando a cópia sumiu (apagada pela retenção ou pelo disco). */
    private function readAttachment(Message $message): ?string
    {
        if ($message->media_path === null) {
            return null;
        }

        $disk = Message::mediaDisk();

        return $disk->exists($message->media_path) ? $disk->get($message->media_path) : null;
    }

    private function waitingAhead(Message $message): bool
    {
        return Message::query()
            ->where('channel_id', $message->channel_id)
            ->where('delivery_status', DeliveryStatus::QUEUED->value)
            ->where('id', '<', $message->id)
            ->exists();
    }
}
