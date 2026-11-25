<?php

namespace App\Services\Bot;

use App\Models\BotSession;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\WorkflowVersion;
use App\Services\Bot\Effects\LiveEffects;
use App\Services\Bot\Flow\FlowGraph;

/** Monta a execução de produção do bot: abre a sessão de um ticket, retoma a que já existe e libera o ticket sem workflow. */
final class LiveSessions
{
    public function start(BotEngine $engine, Ticket $ticket, WorkflowVersion $version): BotRun
    {
        $flow = FlowGraph::fromArray($version->graph);

        $session = BotSession::create([
            'ticket_id' => $ticket->id,
            'workflow_version_id' => $version->id,
            'node_id' => $flow->start(),
            'vars' => [],
            'state' => BotSession::ACTIVE,
        ]);
        TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'bot_started', 'payload' => ['workflow_version_id' => $version->id]]);

        return new BotRun($engine, $session, $flow, new LiveEffects($ticket));
    }

    public function resume(BotEngine $engine, BotSession $session): BotRun
    {
        return new BotRun(
            $engine,
            $session,
            FlowGraph::fromArray($session->version()->firstOrFail()->graph),
            new LiveEffects($session->ticket()->firstOrFail()),
        );
    }

    /** O canal deixou de ter workflow antes de a conversa começar: o ticket vai direto à fila geral. */
    public function release(Ticket $ticket): void
    {
        (new LiveEffects($ticket))->enterQueue(null, null);
        TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'bot_handoff', 'payload' => ['reason' => 'sem_workflow']]);
    }
}
