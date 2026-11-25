<?php

namespace App\Services\Contacts;

use App\Enums\TicketStatus;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Google\ContactSyncStatus;

/** O que a ficha do contato mostra além do formulário: etiquetas, sincronização com o Google e atendimentos anteriores. */
class ContactCardData
{
    private const HISTORY_LIMIT = 5;

    public function __construct(private readonly ContactSyncStatus $syncStatus) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Contact $contact, ?int $currentTicketId, User $user): array
    {
        $others = Ticket::query()->where('contact_id', $contact->id)->when($currentTicketId !== null, fn ($query) => $query->whereKeyNot($currentTicketId));
        $history = (clone $others)->with(['sector', 'channel'])->latest('opened_at')->limit(self::HISTORY_LIMIT)->get();
        $historyTotal = $others->count();

        $contactTags = Tag::sortedByName($contact->tags()->get());

        $current = $currentTicketId === null ? null : Ticket::query()->select(['id', 'channel_id', 'status'])->find($currentTicketId);

        return [
            'contactTags' => $contactTags,
            'availableTags' => Tag::sortedByName(Tag::query()->ofContact()->whereNotIn('id', $contactTags->pluck('id'))->get()),
            'google' => $this->syncStatus->describe($contact),
            'history' => $history,
            'historyTotal' => $historyTotal,
            'hasMore' => $historyTotal > self::HISTORY_LIMIT,
            'openable' => Ticket::query()->readableBy($user)->whereIn('id', $history->modelKeys())->pluck('id')->all(),
            // "Conversar" abre Nova conversa com o contato: em ticket ativo a conversa já está aberta, então só serve em encerrado ou sem ticket.
            'canChat' => $current === null || $current->status === TicketStatus::ENCERRADO,
            'currentChannelId' => $current?->channel_id,
        ];
    }
}
