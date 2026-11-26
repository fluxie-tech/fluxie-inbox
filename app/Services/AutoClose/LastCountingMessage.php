<?php

namespace App\Services\AutoClose;

use App\Enums\MessageDirection;
use App\Models\Message;
use App\Models\Ticket;

/**
 * A última mensagem que conta para o encerramento automático: a mais recente do ticket que não é resposta automática
 * (a mensagem revogada conta igual). Nossa é a que sai (atendente, celular ou Automação); a do cliente é dele.
 */
class LastCountingMessage
{
    public function for(Ticket $ticket): ?Message
    {
        return $ticket->messages()->where('auto_reply', false)->orderByDesc('sent_at')->orderByDesc('id')->first();
    }

    public function isOurs(Message $message): bool
    {
        return $message->direction === MessageDirection::OUT;
    }
}
