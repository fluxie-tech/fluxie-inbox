<?php

namespace App\Services\Inbox;

use App\Models\Ticket;
use App\Models\User;

/** As leituras de ticket "só se o usuário pode vê-lo" da tela de atendimento. */
final class TicketLookup
{
    public function canSee(User $user, int $ticketId): bool
    {
        return Ticket::query()->readableBy($user)->whereKey($ticketId)->exists();
    }

    /** O ticket aberto na tela, desde que o usuário ainda possa vê-lo (outro atendente pode ter assumido). */
    public function selected(User $user, ?int $ticketId): Ticket
    {
        return Ticket::query()->readableBy($user)->findOrFail($ticketId);
    }

    public function detailed(User $user, ?int $ticketId): ?Ticket
    {
        return $ticketId === null
            ? null
            : Ticket::query()->readableBy($user)->with(['contact', 'channel', 'assignee', 'sector', 'tags'])->find($ticketId);
    }

    /** Só o protocolo, e só se o usuário ainda pode ver o ticket (ele pode ter sido transferido enquanto isso). */
    public function previous(User $user, ?int $previousTicketId): ?Ticket
    {
        return $previousTicketId === null
            ? null
            : Ticket::query()->readableBy($user)->select(['id', 'protocol'])->find($previousTicketId);
    }

    /**
     * Vindo da janela "Nova conversa" com um ticket que já existia: o aviso e, se pode responder, o texto digitado.
     *
     * @return array{notice: ?string, draft: ?string}
     */
    public function handoff(User $user, int $ticketId): array
    {
        return ConversationHandoff::pull($user, Ticket::query()->findOrFail($ticketId));
    }
}
