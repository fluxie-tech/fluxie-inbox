<?php

namespace App\Services\Inbox;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Grava a mensagem de entrada (ticket, atividade, linha e anúncio) numa transação só. */
class StoreInbound
{
    public function __construct(
        private readonly InboundTicket $tickets,
        private readonly InboundRow $rows,
        private readonly AnnounceInbound $announce,
    ) {}

    public function __invoke(Channel $channel, Contact $contact, IncomingMessage $incoming, IngestMode $mode, CarbonInterface $freshAt, CarbonInterface $arrivedAt): Message
    {
        try {
            return DB::transaction(fn () => $this->store($channel, $contact, $incoming, $mode, $freshAt, $arrivedAt));
        } catch (UniqueConstraintViolationException $e) {
            // Corrida com outro job da mesma mensagem: a linha dele vale, a nossa é descartada.
            return Message::query()->where('channel_id', $channel->id)->where('wa_message_id', $incoming->waId)->first() ?? throw $e;
        }
    }

    private function store(Channel $channel, Contact $contact, IncomingMessage $incoming, IngestMode $mode, CarbonInterface $freshAt, CarbonInterface $arrivedAt): Message
    {
        $ticket = $this->tickets->for($channel, $contact, $incoming, $mode, $freshAt, $arrivedAt);

        // Importada é histórico: não abre nem fecha espera de resposta.
        if ($mode !== IngestMode::IMPORTED) {
            $this->recordActivity($ticket, $incoming);
        }

        $message = $this->rows->create($ticket, $contact, $incoming);
        ($this->announce)($message, $ticket, $incoming, $mode, $freshAt, $arrivedAt);

        return $message;
    }

    private function recordActivity(Ticket $ticket, IncomingMessage $incoming): void
    {
        $incoming->fromMe ? $ticket->recordOutbound($incoming->sentAt) : $ticket->recordInbound($incoming->sentAt);
    }
}
