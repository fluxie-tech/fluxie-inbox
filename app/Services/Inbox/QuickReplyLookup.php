<?php

namespace App\Services\Inbox;

use App\Models\QuickReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/** As respostas rápidas que o atendente pode usar na barra de envio. */
final class QuickReplyLookup
{
    /**
     * Todas as respostas que o usuário pode usar, para o botão (o `/` filtra pelo atalho, ver `matching`).
     *
     * @return Collection<int, QuickReply>
     */
    public function all(User $user): Collection
    {
        return QuickReply::forUser($user)->orderBy('shortcut')->limit(50)->get();
    }

    public function body(User $user, int $id): string
    {
        return QuickReply::forUser($user)->findOrFail($id)->body;
    }

    /** O rascunho `/pre` com um atalho cadastrado: o texto dele, ou nulo se o rascunho não é um atalho ou ele não existe. */
    public function expandShortcut(User $user, string $draft): ?string
    {
        if (preg_match('#^/([a-z0-9_-]+)$#i', $draft, $m) !== 1) {
            return null;
        }

        return QuickReply::forUser($user)->where('shortcut', strtolower($m[1]))->first()?->body;
    }

    /**
     * @return Collection<int, QuickReply>
     */
    public function matching(User $user, string $draft): Collection
    {
        return QuickReply::forUser($user)->matching($draft)->get();
    }
}
