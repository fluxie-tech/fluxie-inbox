<?php

namespace App\Services\AutoClose;

use App\Enums\ChannelStatus;
use App\Enums\MessageOrigin;
use App\Jobs\SendTicketMessageJob;
use App\Models\Ticket;
use App\Services\Inbox\OutgoingMessages;
use App\Settings\AutoCloseSettings;

/** As mensagens que o encerramento automático manda ao cliente: o aviso e a despedida, sem assinatura de atendente. */
class AutoCloseMessages
{
    public function __construct(private readonly OutgoingMessages $messages) {}

    /** Canal parado ou desativado: não sai aviso nem despedida. */
    public function canMessage(Ticket $ticket): bool
    {
        $channel = $ticket->channel;

        return $channel !== null && $channel->active && $channel->status === ChannelStatus::WORKING;
    }

    /** Cria a mensagem do passo dado e a põe na fila; falso se o passo não manda nada (despedida desligada ou canal parado). */
    public function send(AutoCloseStep $step, Ticket $ticket): bool
    {
        $template = match (true) {
            $step === AutoCloseStep::WARN => AutoCloseSettings::warnMessage(),
            $step === AutoCloseStep::CLOSE && AutoCloseSettings::farewellEnabled() && $this->canMessage($ticket) => AutoCloseSettings::farewellMessage(),
            default => null,
        };

        if ($template === null) {
            return false;
        }

        $message = $this->messages->create($ticket, null, AutoCloseSettings::render($template, $ticket), MessageOrigin::BOT, autoReply: true);
        SendTicketMessageJob::dispatch($message->id);

        return true;
    }
}
