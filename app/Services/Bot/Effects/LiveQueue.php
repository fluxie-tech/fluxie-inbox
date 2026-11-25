<?php

namespace App\Services\Bot\Effects;

use App\Actions\Inbox\ClaimTicket;
use App\Actions\Inbox\TransferTicket;
use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Services\Alerts\TicketAlerts;

/** A entrada do ticket na fila, com ou sem atribuição a uma pessoa. */
final class LiveQueue
{
    public function __construct(private readonly Ticket $ticket) {}

    /**
     * Entra na fila e, se a pessoa escolhida pode, passa o ticket a ela; devolve o id de quem assumiu. Com atribuição possível a
     * fila não toca o som; se a atribuição falhar, ela é avisada em seguida.
     */
    public function enter(?Sector $sector, ?User $assignTo): ?int
    {
        $user = self::assignable($assignTo, $sector);

        $this->queue($sector, announce: $user === null);
        $assigned = $user !== null ? $this->assign($user) : null;

        if ($user !== null && $assigned === null) {
            app(TicketAlerts::class)->queued($this->ticket->refresh());
        }

        return $assigned;
    }

    /** A pessoa, se ela existe, está ativa e atende o setor (ticket sem setor é de todos); senão nulo. */
    public static function assignable(?User $user, ?Sector $sector): ?User
    {
        return $user !== null && $user->active && $user->canHandleSector($sector?->id) ? $user : null;
    }

    /** Passa o ticket (já na fila) à pessoa; devolve o id dela, ou nulo se ele já tinha dono ou foi assumido antes. */
    private function assign(User $user): ?int
    {
        if (! (new ClaimTicket)($this->ticket, $user)) {
            return null;
        }

        app(TicketAlerts::class)->assigned($this->ticket, $user, null);

        return $user->id;
    }

    private function queue(?Sector $sector, bool $announce): void
    {
        if ($sector !== null) {
            (new TransferTicket)($this->ticket, null, $sector, announce: $announce);

            return;
        }

        // Nenhum setor recebe tickets: vai para a fila geral, que o atendente sem setor e os supervisores veem.
        $moved = Ticket::query()->whereKey($this->ticket->id)->where('status', TicketStatus::PENDENTE->value)->update([
            'status' => TicketStatus::AGUARDANDO->value,
            'waiting_since' => now(),
            'sla_warn_at' => null,
            'sla_due_at' => null,
            'sla_warned_at' => null,
            'sla_breached_at' => null,
            'updated_at' => now(),
        ]) === 1;

        if ($moved) {
            TicketEvent::create(['ticket_id' => $this->ticket->id, 'type' => 'transferred', 'payload' => []]);
            $this->ticket->refresh();
            TicketUpdated::dispatch($this->ticket);
            if ($announce) {
                app(TicketAlerts::class)->queued($this->ticket);
            }
        }
    }
}
