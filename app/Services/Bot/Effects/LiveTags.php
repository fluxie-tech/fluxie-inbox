<?php

namespace App\Services\Bot\Effects;

use App\Enums\TagScope;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** As etiquetas que o bot aplica e consulta, no contato ou no ticket conforme o escopo. */
final class LiveTags
{
    public function __construct(private readonly Ticket $ticket) {}

    public function tag(Tag $tag): void
    {
        $this->tagOwner($tag)?->syncWithoutDetaching([$tag->id]);
    }

    public function hasTag(Tag $tag): bool
    {
        return $this->tagOwner($tag)?->whereKey($tag->id)->exists() ?? false;
    }

    /**
     * Onde a etiqueta mora, ou nulo se o contato não existe mais: efeito opcional, o fluxo do bot segue.
     *
     * @return BelongsToMany<Tag, Contact>|BelongsToMany<Tag, Ticket>|null
     */
    private function tagOwner(Tag $tag): ?BelongsToMany
    {
        return $tag->scope === TagScope::CONTATO ? $this->ticket->contact()->first()?->tags() : $this->ticket->tags();
    }
}
