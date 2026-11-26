<?php

namespace App\Actions\Contacts;

use App\Events\TicketUpdated;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\Ticket;

/** Etiquetas do contato: só `attach`/`detach`, o contato não é tocado, então o Google não é reenviado. */
class ContactTags
{
    /** Etiquetas do contato por ficha: acima disso a ficha vira um mural de chips. */
    public const MAX_TAGS = 20;

    public const ADDED = 'added';

    public const IGNORED = 'ignored';

    public const LIMIT = 'limit';

    /** Aplica só etiqueta do escopo `contato`. */
    public function add(Contact $contact, int $tagId): string
    {
        $tag = Tag::query()->ofContact()->find($tagId);

        if ($tag === null || $contact->tags()->whereKey($tag->id)->exists()) {
            return self::IGNORED;
        }

        if ($contact->tags()->count() >= self::MAX_TAGS) {
            return self::LIMIT;
        }

        $contact->tags()->attach($tag->id);
        $this->announce($contact);

        return self::ADDED;
    }

    /** @return array{removed: bool, name: ?string} */
    public function remove(Contact $contact, int $tagId): array
    {
        $name = Tag::query()->find($tagId)?->name;
        $removed = $contact->tags()->detach($tagId) > 0;

        if ($removed) {
            $this->announce($contact);
        }

        return ['removed' => $removed, 'name' => $name];
    }

    /** O card da fila mostra as etiquetas do contato: os atendentes se atualizam pelo Reverb (um aviso por ticket aberto; encerrado não aparece na fila). */
    private function announce(Contact $contact): void
    {
        Ticket::query()->open()->where('contact_id', $contact->id)->each(fn (Ticket $ticket) => TicketUpdated::dispatch($ticket));
    }
}
