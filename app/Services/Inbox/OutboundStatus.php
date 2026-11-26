<?php

namespace App\Services\Inbox;

use App\Enums\DeliveryStatus;
use App\Enums\MessageOrigin;
use App\Events\MessageStatusChanged;
use App\Events\TicketUpdated;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketEvent;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** As transições de status da mensagem que sai: enviada, aguardando conexão, arquivo perdido e falha. */
class OutboundStatus
{
    /**
     * @param  array<string, mixed>  $response
     */
    public function sent(Message $message, Ticket $ticket, array $response): void
    {
        $message->refresh();

        $waId = $response['id'] ?? null;

        if ($message->wa_message_id === null && is_string($waId) && $waId !== '') {
            $message->wa_message_id = Message::waKey($waId);
        }

        // Aguardando e falha viram enviada; entregue e lida (que o ack pode ter adiantado) ficam como estão.
        if (in_array($message->delivery_status, [null, DeliveryStatus::QUEUED, DeliveryStatus::FAILED], true)) {
            $message->delivery_status = DeliveryStatus::SENT;
            $message->failure_reason = null;
            $message->failure_code = null;
        }

        $message->save();

        // A resposta automática não é trabalho humano: não marca a primeira resposta nem adia o encerramento por inatividade.
        if ($message->origin !== MessageOrigin::BOT) {
            $ticket->recordOutbound($message->sent_at);
        }

        MessageStatusChanged::dispatch($message);
        TicketUpdated::dispatch($ticket);

        TicketEvent::create([
            'ticket_id' => $ticket->id,
            'type' => 'message_out',
            'actor_user_id' => $message->author_user_id,
            'payload' => ['message_id' => $message->id],
        ]);
    }

    /** Aguardando conexão: nada saiu, e é para a caixa de saída enviar depois. */
    public function park(Message $message): void
    {
        if ($message->delivery_status !== DeliveryStatus::QUEUED) {
            $message->update(['delivery_status' => DeliveryStatus::QUEUED, 'failure_reason' => null, 'failure_code' => null]);
            MessageStatusChanged::dispatch($message);
        }
    }

    /** Sem a cópia local não há o que enviar: falha com motivo (sem laço de reenvio) em vez de ficar esperando. */
    public function fileGone(Message $message): void
    {
        $message->update([
            'delivery_status' => DeliveryStatus::FAILED,
            'failure_code' => SendFailure::REFUSED,
            'failure_reason' => 'O arquivo deste anexo não está mais disponível no servidor. Envie-o de novo.',
        ]);
        MessageStatusChanged::dispatch($message);
    }

    /** O aviso que esperava na fila e o chamado foi encerrado antes de sair: não se envia, e fica sem botão de reenvio. */
    public function cancel(Message $message): void
    {
        $message->update([
            'delivery_status' => DeliveryStatus::FAILED,
            'failure_code' => SendFailure::CANCELLED,
            'failure_reason' => SendFailure::reason(SendFailure::CANCELLED, new RuntimeException),
        ]);
        MessageStatusChanged::dispatch($message);
    }

    /**
     * O que fazer com um envio que falhou depende de a mensagem poder ter saído (ver `SendFailure`): a que certamente não
     * saiu volta para a fila; as demais viram falha com o motivo, e só o tipo certo ganha botão de reenvio.
     */
    public function failed(Message $message, Throwable $e): void
    {
        // Se o eco do celular já casou com a linha (ela ganhou o id), a mensagem saiu: não é falha.
        if ($message->refresh()->wa_message_id !== null) {
            throw $e;
        }

        $kind = SendFailure::kind($e);

        if ($kind === SendFailure::SESSION_DOWN) {
            $this->park($message);

            return;
        }

        if ($kind === SendFailure::RATE_LIMIT) {
            Log::warning('WhatsApp limitou o envio (463/475): pausar, sem reiniciar nem repareiar a sessão', ['erro' => $e->getMessage()]);
        }

        $message->update([
            'delivery_status' => DeliveryStatus::FAILED,
            'failure_code' => $kind,
            'failure_reason' => SendFailure::reason($kind, $e),
        ]);
        MessageStatusChanged::dispatch($message);

        throw $e;
    }
}
