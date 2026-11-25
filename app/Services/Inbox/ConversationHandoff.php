<?php

namespace App\Services\Inbox;

use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;

/**
 * Recado da janela "Nova conversa" para o Inbox quando o contato já tinha conversa aberta: o aviso de quem é o ticket e, se o
 * usuário pode responder, o texto que ele digitou (que vai ao campo de resposta, sem ser enviado). Viaja na sessão (um redirect).
 */
final class ConversationHandoff
{
    private const NOTICE = 'inbox-notice';

    private const DRAFT = 'inbox-draft';

    public static function flash(string $notice, string $draft, bool $canReply): void
    {
        session()->flash(self::NOTICE, $notice);

        if ($canReply && $draft !== '') {
            session()->flash(self::DRAFT, $draft);
        }
    }

    /** Já havia conversa aberta: o aviso diz de quem é e, se o usuário pode responder, o texto digitado vai para o campo dele. */
    public static function flashExisting(User $user, Ticket $ticket, Contact $contact, string $text): void
    {
        $owner = $ticket->assignee->name ?? 'a fila';
        $text = trim($text);
        // Sem texto digitado (o desvio do `open` leva direto ao ticket) não há mensagem para levar ao campo.
        $carriesText = $text !== '' && $user->can('reply', $ticket);

        self::flash('Já existe uma conversa aberta com '.$contact->label().' (de '.$owner.').'.($carriesText ? ' Sua mensagem está no campo de resposta.' : ''), $text, $carriesText);
    }

    /** @return array{notice: ?string, draft: ?string} o texto só sai se o usuário ainda pode responder o ticket */
    public static function pull(User $user, Ticket $ticket): array
    {
        $notice = session()->pull(self::NOTICE);
        $draft = session()->pull(self::DRAFT);

        return [
            'notice' => is_string($notice) ? $notice : null,
            'draft' => is_string($draft) && $user->can('reply', $ticket) ? $draft : null,
        ];
    }
}
