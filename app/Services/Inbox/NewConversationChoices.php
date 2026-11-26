<?php

namespace App\Services\Inbox;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/** O que a janela "Nova conversa" oferece: contatos da busca, canais ativos, setores do usuário e as escolhas já preenchidas. */
final class NewConversationChoices
{
    /** A conversa aberta mais recente do contato que o usuário vê, ou nada. */
    public function openTicket(User $user, int $contactId): ?Ticket
    {
        return Ticket::query()->open()->readableBy($user)->where('contact_id', $contactId)->orderByDesc('opened_at')->orderByDesc('id')->first();
    }

    public function channel(?int $id): Channel
    {
        return Channel::query()->findOrFail($id);
    }

    public function sector(?int $id): Sector
    {
        return Sector::query()->findOrFail($id);
    }

    /** @return array{contact: ?Contact, matches: Collection<int, Contact>, channels: Collection<int, Channel>, sectors: Collection<int, Sector>} */
    public function forView(User $user, ?int $contactId, string $search): array
    {
        return [
            'contact' => $contactId === null ? null : Contact::query()->find($contactId),
            'matches' => $contactId === null && mb_strlen(trim($search)) >= 2 ? Contact::query()->with('tags')->search($search)->limit(8)->get() : new Collection,
            'channels' => Channel::query()->where('active', true)->orderBy('name')->get(),
            'sectors' => $this->sectorsFor($user),
        ];
    }

    /**
     * Canal do ticket mais recente do contato (ou o único ativo) e o setor único do atendente.
     *
     * @return array{channelId: ?int, sectorId: ?int}
     */
    public function defaults(User $user, ?Contact $contact): array
    {
        $active = Channel::query()->where('active', true)->get();
        $latest = $contact === null ? null : Ticket::query()->where('contact_id', $contact->id)->latest('opened_at')->first()?->channel_id;
        $sectors = $this->sectorsFor($user);

        return [
            'channelId' => $latest !== null && $active->contains('id', $latest) ? $latest : ($active->count() === 1 ? $active->first()?->id : null),
            'sectorId' => $sectors->count() === 1 ? $sectors->first()?->id : null,
        ];
    }

    /**
     * Setores em que o usuário pode iniciar: supervisor e admin, todos que recebem tickets; atendente, os que cobre.
     *
     * @return Collection<int, Sector>
     */
    private function sectorsFor(User $user): Collection
    {
        $query = $user->canSeeAllSectors() ? Sector::query() : $user->sectors();

        return $query->receivingTickets()->orderBy('menu_order')->get();
    }
}
