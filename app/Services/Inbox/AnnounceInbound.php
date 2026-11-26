<?php

namespace App\Services\Inbox;

use App\Events\MessageCreated;
use App\Events\TicketUpdated;
use App\Jobs\ResolveMessageMediaJob;
use App\Jobs\SubscribeClientPresenceJob;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Services\Bot\BotEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** O que acontece depois que a mensagem de entrada foi gravada: linha do tempo, tempo real, respostas automáticas e mídia. */
class AnnounceInbound
{
    public function __invoke(Message $message, Ticket $ticket, IncomingMessage $incoming, IngestMode $mode, CarbonInterface $freshAt, CarbonInterface $arrivedAt): void
    {
        // Importada é histórico: não gera evento de linha do tempo nem tempo real.
        if ($mode !== IngestMode::IMPORTED) {
            $this->announce($message, $ticket, $incoming, $mode, $freshAt, $arrivedAt);
        }

        if ($message->hasMedia()) {
            ResolveMessageMediaJob::dispatch($message->id, $mode === IngestMode::LIVE ? 'inbound' : 'media')->afterCommit();
        }
    }

    /** Linha do tempo, tempo real e, para mensagem do cliente, as respostas automáticas (só depois do commit). */
    private function announce(Message $message, Ticket $ticket, IncomingMessage $incoming, IngestMode $mode, CarbonInterface $freshAt, CarbonInterface $arrivedAt): void
    {
        TicketEvent::create([
            'ticket_id' => $ticket->id,
            'type' => $incoming->fromMe ? 'message_out' : 'message_in',
            'payload' => ['message_id' => $message->id],
        ]);

        // Recuperada não toca o som de "mensagem nova" nem manda "visto" ao cliente por mensagem antiga: só a lista e o ticket se atualizam.
        if ($mode === IngestMode::LIVE) {
            MessageCreated::dispatch($message);
            // O cliente acabou de escrever: abre a janela curta em que a presença dele (digitando, gravando) é assinada.
            SubscribeClientPresenceJob::whenClientWrote($ticket->id, $incoming->fromMe);
        }

        TicketUpdated::dispatch($ticket);

        // Ao vivo, o bot e a resposta fora do horário valem mesmo que o job tenha atrasado; recuperada só se o cliente ainda espera.
        if (! $incoming->fromMe && ($mode === IngestMode::LIVE || app(BotEntry::class)->isFresh($freshAt, $arrivedAt))) {
            // Depois do commit: só uma mensagem realmente gravada pode gerar resposta (a que perdeu a corrida é descartada).
            DB::afterCommit(function () use ($message, $freshAt): void {
                app(AfterHoursReply::class)($message, $freshAt);
                app(BotEntry::class)->handle($message);
            });
        }
    }
}
