<?php

namespace App\Services\Inbox;

use App\Events\TicketUpdated;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketNote;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Apagar uma nota interna e desfazer: a nota fica guardada no cache do usuário sob um token aleatório. */
final class TicketNoteTrash
{
    /** Quanto tempo o "Desfazer" de uma nota apagada vale. */
    private const UNDO_SECONDS = 60;

    public function find(int $ticketId, int $noteId): ?TicketNote
    {
        return TicketNote::query()->where('ticket_id', $ticketId)->find($noteId);
    }

    /** Guarda a nota no cache, apaga, deixa o rastro na auditoria e devolve o token do "Desfazer". */
    public function delete(TicketNote $note, User $by): string
    {
        $token = Str::random(32);
        Cache::put($this->undoKey($by->id, $token), ['ticket_id' => $note->ticket_id, 'user_id' => $note->user_id, 'origin' => $note->origin->value, 'body' => $note->body, 'created_at' => $note->created_at->toIso8601String()], self::UNDO_SECONDS);

        $note->delete();
        TicketEvent::create(['ticket_id' => $note->ticket_id, 'type' => 'note_deleted', 'actor_user_id' => $by->id, 'payload' => ['note_id' => $note->id]]);
        $this->touchTicket($note->ticket_id);

        return $token;
    }

    /** Recria a nota idêntica (mesmo autor, origem e horário), uma vez só, e só neste ticket; false se nada foi recriado. */
    public function restore(User $by, int $ticketId, string $token): bool
    {
        // O evento chega a todo painel aberto: só gasta o token quem é do ticket certo, e `forget` devolve true a um único chamador.
        $saved = Cache::get($this->undoKey($by->id, $token));

        $createdAt = is_array($saved) ? ($saved['created_at'] ?? null) : null;

        // Quem apagou pode ter sido inativado, ou o ticket ter sumido (mesclado, apagado), na janela de 60 s: nada é recriado e o token não é gasto.
        if (! is_array($saved) || ($saved['ticket_id'] ?? null) !== $ticketId || ! is_string($createdAt)
            || ! Ticket::query()->readableBy($by)->whereKey($ticketId)->exists()
            || ! Cache::forget($this->undoKey($by->id, $token))) {
            return false;
        }

        $note = new TicketNote(['ticket_id' => $ticketId, 'user_id' => $saved['user_id'], 'origin' => $saved['origin'], 'body' => $saved['body']]);
        $note->created_at = CarbonImmutable::parse($createdAt);
        $note->save();

        TicketEvent::create(['ticket_id' => $ticketId, 'type' => 'note_restored', 'actor_user_id' => $by->id, 'payload' => ['note_id' => $note->id]]);
        $this->touchTicket($ticketId);

        return true;
    }

    /** Por usuário: o token de outra pessoa não acha nada. */
    private function undoKey(int $userId, string $token): string
    {
        return 'undo-note:'.$userId.':'.$token;
    }

    private function touchTicket(int $ticketId): void
    {
        $ticket = Ticket::find($ticketId);

        if ($ticket !== null) {
            TicketUpdated::dispatch($ticket);
        }
    }
}
