<?php

namespace App\Services\Inbox;

use App\Enums\TicketStatus;
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

    /** O ticket, ou nulo se não existe ou o usuário não pode vê-lo (quem chama decide o que fazer). */
    public function readable(User $user, int $ticketId): ?Ticket
    {
        return Ticket::query()->readableBy($user)->find($ticketId);
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

    /** O ticket de um aviso de presença: nasce da conversa do gravador, não da seleção atual (ver `ComposesMessages`). */
    public function visibleOrFail(User $user, int $ticketId): Ticket
    {
        return Ticket::query()->visibleTo($user)->findOrFail($ticketId);
    }

    /**
     * Dos ids com rascunho guardado no navegador, os de ticket encerrado que o usuário ainda vê (o navegador apaga esses
     * rascunhos). Só inteiros positivos, até 200: o id vem do navegador.
     *
     * @param  array<mixed>  $ids
     * @return list<int>
     */
    public function closedAmong(User $user, array $ids): array
    {
        $valid = array_values(array_filter(array_slice($ids, 0, 200), fn ($id): bool => is_int($id) && $id > 0));

        $closed = Ticket::query()->readableBy($user)
            ->whereIn('id', $valid)
            ->where('status', TicketStatus::ENCERRADO->value)
            ->get(['id'])
            ->map(fn (Ticket $ticket): int => $ticket->id);

        return array_values($closed->all());
    }
}
