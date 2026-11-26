<?php

namespace App\Livewire\Contacts;

use App\Actions\Contacts\ContactTags;
use App\Actions\Contacts\ResyncContact;
use App\Actions\Contacts\SaveContact;
use App\Livewire\Forms\ContactForm;
use App\Models\Contact;
use App\Models\User;
use App\Services\Contacts\ContactCardData;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/** Ficha editável do contato: vive no painel do inbox e na tela Contatos. */
class ContactCard extends Component
{
    public const MAX_TAGS = ContactTags::MAX_TAGS;

    #[Locked]
    public int $contactId;

    public ContactForm $form;

    /** No Inbox a ficha abre em resumo; o formulário só aparece em "Editar". Em Contatos (gaveta) abre na ficha de leitura, também com "Editar". */
    public bool $compact = false;

    public bool $editing = false;

    /** O ticket em que a ficha está aberta: não entra na lista de atendimentos anteriores. */
    #[Locked]
    public ?int $currentTicketId = null;

    /** Histórico: só leitura, sem o botão Editar. */
    #[Locked]
    public bool $readonly = false;

    public function mount(int $contactId, bool $compact = false, bool $readonly = false, ?int $currentTicketId = null): void
    {
        $this->currentTicketId = $currentTicketId;
        $this->contactId = $contactId;
        $this->compact = $compact;
        $this->readonly = $readonly;
        $this->form->fillFrom($this->contact());
    }

    public function toggleEdit(): void
    {
        abort_if($this->readonly, 403);

        $this->editing = ! $this->editing;
        $this->form->fillFrom($this->contact());
        $this->resetErrorBag();
    }

    public function save(SaveContact $saveContact): void
    {
        abort_if($this->readonly, 403);

        $this->form->validate();

        $saveContact($this->contact(), $this->form->payload());
        $this->editing = false;

        $this->dispatch('contact-saved', contactId: $this->contactId);
    }

    public function resync(ResyncContact $resync): void
    {
        abort_if($this->readonly, 403);

        $resync($this->contact());
    }

    public function addTag(int $tagId, ContactTags $tags): void
    {
        abort_if($this->readonly, 403);

        $result = $tags->add($this->contact(), $tagId);

        if ($result === ContactTags::LIMIT) {
            $this->addError('tag', 'Limite de '.ContactTags::MAX_TAGS.' etiquetas por contato.');

            return;
        }

        if ($result === ContactTags::ADDED) {
            $this->resetErrorBag('tag');
            $this->dispatch('tags-changed');
        }
    }

    public function removeTag(int $tagId, ContactTags $tags): void
    {
        abort_if($this->readonly, 403);

        $result = $tags->remove($this->contact(), $tagId);
        $this->resetErrorBag('tag');

        if ($result['removed']) {
            $this->dispatch('tags-changed');
            $this->dispatch('tag-removed');

            if ($result['name'] !== null) {
                Flux::toast(text: 'Etiqueta «'.$result['name'].'» removida.', duration: 6000, action: [
                    'label' => 'Desfazer', 'event' => 'undo-contact-tag', 'params' => ['ownerId' => $this->contactId, 'tagId' => $tagId],
                ]);
            }
        }
    }

    /** O "Desfazer" do aviso de remoção (só age a ficha do próprio contato). Reaproveita as regras do `addTag`, inclusive o limite. */
    #[On('undo-contact-tag')]
    public function undoRemove(int $ownerId, int $tagId, ContactTags $tags): void
    {
        if ($ownerId === $this->contactId) {
            $this->addTag($tagId, $tags);
        }
    }

    public function addUrl(): void
    {
        if (count($this->form->urls) < ContactForm::MAX_URLS) {
            $this->form->urls[] = '';
        }
    }

    public function removeUrl(int $index): void
    {
        unset($this->form->urls[$index]);
        $this->form->urls = array_values($this->form->urls);
    }

    private function contact(): Contact
    {
        return Contact::query()->findOrFail($this->contactId);
    }

    /** Um ticket do contato mudou: a lista de atendimentos anteriores se atualiza, menos com o formulário aberto. */
    #[On('echo-private:inbox,.ticket.updated')]
    #[On('realtime-reconnected')]
    public function refresh(): void
    {
        if ($this->editing || ! $this->compact) {
            $this->skipRender();
        }
    }

    public function render(ContactCardData $data): View
    {
        $contact = $this->contact();
        $user = auth()->user();
        assert($user instanceof User);

        return view('livewire.contacts.contact-card', ['contact' => $contact] + $data->for($contact, $this->currentTicketId, $user));
    }
}
