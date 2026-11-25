<?php

namespace App\Livewire\Inbox;

use App\Actions\Inbox\ContactFromCard;
use App\Actions\Inbox\MarkTicketSeenByAgent;
use App\Jobs\MarkTicketSeenJob;
use App\Models\User;
use App\Services\Inbox\TicketLookup;
use App\Services\Inbox\TicketNoteTrash;
use App\Services\Inbox\TranscriptQuery;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * As mensagens do ticket aberto. Vive fora do `Inbox` para que só um evento de mensagem (ou o `transcript-refresh`
 * que o `Inbox` dispara depois de enviar) o renderize: digitar, abrir painéis e eventos da lista não o tocam.
 */
class Transcript extends Component
{
    #[Locked]
    public int $ticketId;

    /** A aba está visível? O navegador atualiza (resources/js/transcript-visibility.js): em segundo plano a mensagem fica não lida. */
    public bool $visible = true;

    /** @param  array<string, mixed>  $payload */
    #[On('echo-private:inbox,.message.created')]
    #[On('echo-private:inbox,.message.status')]
    #[On('echo-private:inbox,.ticket.updated')]
    public function refreshForEvent(array $payload = []): void
    {
        $id = $payload['ticket_id'] ?? null;

        if (! is_numeric($id) || (int) $id !== $this->ticketId) {
            $this->skipRender();

            return;
        }

        // Só `message.created` traz `direction`. Mensagem do cliente no ticket que está na tela: o atendente a vê, o cliente recebe o "visto".
        if (($payload['direction'] ?? null) === 'in') {
            MarkTicketSeenJob::dispatch($this->ticketId);

            if ($this->visible) {
                $this->markSeenByAgent();
            }
        }
    }

    /** A aba voltou a ficar visível: o que chegou em segundo plano conta como visto agora. */
    #[Renderless]
    public function seen(): void
    {
        $this->markSeenByAgent();
    }

    /** Só vale para quem pode ver o ticket (e, dentro da ação, só para o responsável ou em ticket sem responsável). */
    private function markSeenByAgent(): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $ticket = app(TicketLookup::class)->readable($user, $this->ticketId);

        if ($ticket !== null) {
            app(MarkTicketSeenByAgent::class)($ticket, $user);
        }
    }

    #[On('transcript-refresh')]
    #[On('realtime-reconnected')]
    public function refresh(): void {}

    /**
     * Só o autor apaga a própria nota; fica o rastro na auditoria do ticket. Sem confirmação: o aviso oferece "Desfazer" por 60 s
     * (a nota fica guardada no cache do usuário sob um token aleatório).
     */
    public function deleteNote(int $noteId, TicketNoteTrash $trash): void
    {
        $note = $trash->find($this->ticketId, $noteId);

        if ($note === null) {
            return;
        }

        $this->authorize('delete', $note);

        $user = auth()->user();
        assert($user instanceof User);

        $token = $trash->delete($note, $user);

        Flux::toast(text: 'Nota apagada.', duration: 8000, action: ['label' => 'Desfazer', 'event' => 'undo-note-delete', 'params' => ['token' => $token]]);
    }

    /** O "Desfazer" do aviso: recria a nota idêntica (mesmo autor, origem e horário), uma vez só, e só neste ticket. */
    #[On('undo-note-delete')]
    public function restoreNote(string $token, TicketNoteTrash $trash): void
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $trash->restore($user, $this->ticketId, $token);
        }
    }

    /** "Adicionar aos contatos" no cartão de contato recebido: cria o contato (ou reaproveita o que já existe) sem duplicar. */
    public function addContact(int $messageId, int $index, ContactFromCard $fromCard): void
    {
        $fromCard($this->ticketId, $messageId, $index);
    }

    /** "Conversar" no cartão: garante o contato e abre a janela Nova conversa com ele. */
    public function chatWith(int $messageId, int $index, ContactFromCard $fromCard): void
    {
        $contact = $fromCard($this->ticketId, $messageId, $index);

        if ($contact !== null) {
            $this->dispatch('start-conversation', contactId: $contact->id);
        }
    }

    public function render(TranscriptQuery $query): View
    {
        $user = auth()->user();
        assert($user instanceof User);

        return view('livewire.inbox.messages', $query->for($user, $this->ticketId));
    }
}
