<?php

namespace App\Services\Inbox;

use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Services\Alerts\TicketAlerts;
use App\Services\Bot\BotEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** O ticket de uma mensagem que chegou: o aberto do contato no canal, o importado, ou um novo. */
class InboundTicket
{
    /** O ticket da mensagem; resposta pelo celular a uma conversa em triagem automática tira o ticket das mãos do bot. */
    public function for(Channel $channel, Contact $contact, IncomingMessage $incoming, IngestMode $mode, CarbonInterface $freshAt, CarbonInterface $arrivedAt): Ticket
    {
        $ticket = $mode === IngestMode::IMPORTED
            ? app(ImportedTicket::class)($channel, $contact, $incoming)
            : $this->openFor($channel, $contact, $incoming, $mode, $freshAt, $arrivedAt);

        if ($incoming->fromMe && $ticket->status === TicketStatus::PENDENTE) {
            app(BotEntry::class)->humanTookOver($ticket);
            $ticket->refresh();
        }

        return $ticket;
    }

    public function hasOpenBefore(Channel $channel, Contact $contact, IncomingMessage $incoming): bool
    {
        return Ticket::query()->open()
            ->where('contact_id', $contact->id)
            ->where('channel_id', $channel->id)
            ->where('opened_at', '<=', $incoming->sentAt)
            ->exists();
    }

    private function openFor(Channel $channel, Contact $contact, IncomingMessage $incoming, IngestMode $mode, CarbonInterface $freshAt, CarbonInterface $arrivedAt): Ticket
    {
        // Serializa a abertura de ticket por contato e canal: duas mensagens simultâneas do mesmo cliente
        // não disputam o índice único (o job perdedor perderia a mensagem, pois o WAHA não reenvia).
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ['ticket:'.$contact->id.':'.$channel->id]);

        $ticket = Ticket::query()
            ->open()
            ->where('contact_id', $contact->id)
            ->where('channel_id', $channel->id)
            ->first();

        if ($ticket !== null) {
            return $ticket;
        }

        $ticket = Ticket::create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'status' => match (true) {
                $incoming->fromMe => TicketStatus::EM_ATENDIMENTO,
                // Canal com workflow: o bot conduz primeiro e só então o ticket entra na fila de um setor.
                app(BotEntry::class)->shouldStart($channel, $freshAt, $arrivedAt) => TicketStatus::PENDENTE,
                default => TicketStatus::AGUARDANDO,
            },
            'opened_at' => $incoming->sentAt,
        ]);

        TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'opened']);

        // Recuperação não toca o som de fila por ticket: o aviso é um só, no fim (ver `RecoverMessagesJob`).
        if ($ticket->status === TicketStatus::AGUARDANDO && $mode === IngestMode::LIVE) {
            app(TicketAlerts::class)->queued($ticket);
        }

        return $ticket;
    }
}
