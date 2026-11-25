<?php

namespace App\Livewire\Inbox;

use App\Actions\Inbox\ResolveTypedContact;
use App\Actions\Inbox\StartConversation;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\User;
use App\Services\Inbox\ConversationHandoff;
use App\Services\Inbox\ConversationNotStarted;
use App\Services\Inbox\NewConversationChoices;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/** A janela "Nova conversa": aberta pelo evento `start-conversation` (Fila, Contatos, ficha e cartão de contato recebido). */
class NewConversation extends Component
{
    public ?int $contactId = null;

    public string $search = '';

    public string $phone = '';

    public string $name = '';

    public ?int $channelId = null;

    public ?int $sectorId = null;

    public string $text = '';

    /** Por que a conversa não começou (a mensagem vem da ação, pronta para a tela). */
    public ?string $failure = null;

    #[On('start-conversation')]
    public function open(?int $contactId = null): void
    {
        $this->reset('search', 'phone', 'name', 'text', 'failure', 'channelId', 'sectorId');
        $this->resetErrorBag();
        $this->contactId = null;

        if ($contactId !== null) {
            $open = $this->choices()->openTicket($this->user(), $contactId);

            // Já há conversa aberta que o usuário vê: não faz sentido pedir canal, setor e mensagem; leva direto a ela.
            if ($open !== null) {
                ConversationHandoff::flashExisting($this->user(), $open, Contact::query()->findOrFail($contactId), $this->text);
                $this->redirectRoute('inbox', ['ticket' => $open->id]);

                return;
            }

            $this->pick($contactId);
            $this->dispatch('modal-show', name: 'new-conversation');

            return;
        }

        $this->preselect(null);
        $this->dispatch('modal-show', name: 'new-conversation');
    }

    public function pick(int $id): void
    {
        $contact = Contact::query()->findOrFail($id);

        $this->contactId = $contact->id;
        $this->search = '';
        $this->preselect($contact);
    }

    public function clearContact(): void
    {
        $this->contactId = null;
    }

    public function submit(StartConversation $start, ResolveTypedContact $typed): void
    {
        $this->failure = null;
        $this->resetErrorBag();

        $this->validate(
            ['channelId' => ['required', 'integer'], 'sectorId' => ['required', 'integer'], 'text' => ['required', 'string', 'max:'.StartConversation::TEXT_LIMIT]],
            ['channelId.required' => 'Escolha o canal.', 'sectorId.required' => 'Escolha o setor.', 'text.required' => 'Escreva a primeira mensagem.', 'text.max' => 'A mensagem passa de '.StartConversation::TEXT_LIMIT.' caracteres.'],
        );

        $channel = $this->choices()->channel($this->channelId);
        $sector = $this->choices()->sector($this->sectorId);

        try {
            $contact = $this->contactId !== null ? Contact::query()->findOrFail($this->contactId) : $this->typedContact($typed, $channel);

            if ($contact === null) {
                return;
            }

            $result = $start($this->user(), $contact, $channel, $sector, $this->text);
        } catch (ConversationNotStarted $e) {
            $this->failure = $e->getMessage();

            return;
        }

        if (! $result['created']) {
            ConversationHandoff::flashExisting($this->user(), $result['ticket'], $contact, $this->text);
        }

        $this->redirectRoute('inbox', ['ticket' => $result['ticket']->id]);
    }

    public function render(): View
    {
        return view('livewire.inbox.new-conversation', $this->choices()->forView($this->user(), $this->contactId, $this->search));
    }

    /** Telefone digitado vira contato (ou o que já existia); telefone inválido vira erro do campo e devolve nulo. */
    private function typedContact(ResolveTypedContact $typed, Channel $channel): ?Contact
    {
        $result = $typed($this->phone, $this->name, $channel);

        if ($result['phoneError'] !== null) {
            $this->addError('phone', $result['phoneError']);
        }

        return $result['contact'];
    }

    /** Canal do ticket mais recente do contato (ou o único ativo) e o setor único do atendente já vêm preenchidos. */
    private function preselect(?Contact $contact): void
    {
        ['channelId' => $this->channelId, 'sectorId' => $this->sectorId] = $this->choices()->defaults($this->user(), $contact);
    }

    private function choices(): NewConversationChoices
    {
        return app(NewConversationChoices::class);
    }

    private function user(): User
    {
        $user = auth()->user();
        assert($user instanceof User);

        return $user;
    }
}
