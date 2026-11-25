<?php

namespace App\Livewire\Inbox\Concerns;

use App\Actions\Inbox\SendComposedMessage;
use App\Models\QuickReply;
use App\Models\Ticket;
use App\Services\Inbox\AgentRateLimits;
use App\Services\Inbox\AttachmentRules;
use App\Services\Inbox\Presence;
use App\Services\Inbox\QuickReplyLookup;
use App\Services\Inbox\SendTicketMedia;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Renderless;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * A barra de envio do inbox: texto, bandeja de arquivos e gravador de voz. O componente que usa precisa oferecer
 * `selected()` (o ticket na tela, já filtrado pela visibilidade), `user()` e `lookup()`.
 */
trait ComposesMessages
{
    use WithFileUploads;

    public string $draft = '';

    /** A lista do botão "Respostas rápidas" está aberta. */
    public bool $showQuickReplies = false;

    /** O arquivo que acabou de subir; `commitUpload` o move para a bandeja ou o descarta. */
    public ?TemporaryUploadedFile $upload = null;

    /** A gravação de voz que o navegador acabou de subir; `sendVoice` a envia. */
    public ?TemporaryUploadedFile $voice = null;

    /** @var array<int, TemporaryUploadedFile> Bandeja: arquivos aceitos, na ordem de envio. */
    public array $attachments = [];

    /**
     * Dos ids com rascunho guardado no navegador, os de ticket encerrado que o usuário ainda vê (o navegador apaga esses
     * rascunhos). Só inteiros positivos, até 200: o id vem do navegador.
     *
     * @param  array<mixed>  $ids
     * @return list<int>
     */
    #[Renderless]
    public function closedDraftTickets(array $ids): array
    {
        return $this->lookup()->closedAmong($this->user(), $ids);
    }

    public function toggleQuickReplies(): void
    {
        $this->authorize('reply', $this->selected());

        $this->showQuickReplies = ! $this->showQuickReplies;
    }

    /**
     * Todas as respostas que o usuário pode usar, para o botão (o `/` filtra pelo atalho, ver `quickReplies`).
     *
     * @return Collection<int, QuickReply>
     */
    public function allQuickReplies(): Collection
    {
        return app(QuickReplyLookup::class)->all($this->user());
    }

    /** O rascunho `/pre` chama as respostas rápidas do usuário; o texto entra no campo, editável. */
    public function useQuickReply(int $id): void
    {
        $this->authorize('reply', $this->selected());

        $this->draft = app(QuickReplyLookup::class)->body($this->user(), $id);
    }

    /**
     * @return Collection<int, QuickReply>
     */
    public function quickReplies(): Collection
    {
        return app(QuickReplyLookup::class)->matching($this->user(), $this->draft);
    }

    /** Entra na bandeja o arquivo que acabou de subir (um por requisição); o servidor decide se ele fica. */
    public function commitUpload(int $ticketId): void
    {
        $ticket = $this->selected();
        $this->authorize('reply', $ticket);
        $this->resetErrorBag('attachments');

        $file = $this->upload;
        $this->upload = null;

        if ($file === null) {
            return;
        }

        // O arquivo subiu enquanto o atendente estava em outra conversa: não entra na bandeja desta.
        if ($ticketId !== $ticket->id) {
            $file->delete();

            return;
        }

        $error = count($this->attachments) >= AttachmentRules::MAX_FILES
            ? 'No máximo '.AttachmentRules::MAX_FILES.' arquivos por envio.'
            : AttachmentRules::validate($file);

        if ($error !== null) {
            $this->addError('attachments', $error);
            $file->delete();

            return;
        }

        $this->attachments[] = $file;
    }

    public function removeAttachment(int $index): void
    {
        $file = $this->attachments[$index] ?? null;

        if ($file !== null) {
            $file->delete();
            unset($this->attachments[$index]);
            $this->attachments = array_values($this->attachments);
        }
    }

    /**
     * Envia o texto e/ou os arquivos da bandeja. Cada arquivo é uma mensagem; o texto vira a legenda do primeiro, exceto
     * quando o primeiro é áudio (voz não tem legenda) ou a legenda passa de 1024 caracteres: nesses casos sai antes, como
     * mensagem própria. A ordem final é a da fila de saída.
     */
    public function send(): void
    {
        $ticket = $this->selected();
        $this->authorize('reply', $ticket);

        $this->draft = trim($this->draft);

        // `/ok` + Enter vira o texto cadastrado, no campo e editável; o envio é o próximo Enter.
        $expanded = app(QuickReplyLookup::class)->expandShortcut($this->user(), $this->draft);

        if ($expanded !== null) {
            $this->draft = $expanded;

            return;
        }

        $this->resetErrorBag();
        $this->validateDraft();

        $result = app(SendComposedMessage::class)($ticket, $this->user(), $this->draft, array_values($this->attachments), $this->quotedMessage($ticket->id));

        if ($result['error'] !== null) {
            $this->addError('draft', $result['error']);

            return;
        }

        foreach ($this->attachments as $file) {
            $file->delete();
        }

        $this->reset('draft', 'attachments');
        $this->cancelReply();

        foreach ($result['failures'] as $failure) {
            $this->addError('attachments', $failure);
        }

        // O transcript é um componente à parte: só ele sabe que há mensagem nova na linha.
        $this->dispatch('transcript-refresh');
    }

    /**
     * O atendente começou ou parou de gravar áudio: o cliente vê "gravando áudio…". Chega a cada poucos segundos
     * enquanto grava, então tem limite próprio (separado do de envio) e nunca devolve erro ao navegador.
     */
    public function recording(bool $on, int $ticketId): void
    {
        $this->announcePresence($on, $ticketId, recording: true);
    }

    /**
     * O atendente digita no campo: o cliente vê "digitando…". O navegador limita o início (um aviso a cada poucos segundos) e
     * avisa a pausa; aqui só se protege com o teto de chamadas. Não repinta a tela.
     */
    #[Renderless]
    public function typing(bool $on, int $ticketId): void
    {
        $this->announcePresence($on, $ticketId, recording: false);
    }

    private function announcePresence(bool $on, int $ticketId, bool $recording): void
    {
        // O ticket vem do gravador (o da conversa em que ele nasceu), não da seleção atual: ao trocar de conversa, o aviso de
        // "parou" ainda precisa chegar ao cliente certo.
        $ticket = $this->lookup()->visibleOrFail($this->user(), $ticketId);

        if (! $on && ! $this->user()->can('reply', $ticket)) {
            return;
        }

        $this->authorize('reply', $ticket);

        if ($on && ! app(AgentRateLimits::class)->allowPresence($this->user())) {
            return;
        }

        app(Presence::class)->announce($ticket, $on, $recording);
    }

    /** Envia a gravação que o navegador acabou de subir (`voice`) como mensagem de voz do WhatsApp. */
    public function sendVoice(int $ticketId): void
    {
        $ticket = $this->selected();
        $this->authorize('reply', $ticket);
        $this->resetErrorBag('voice');

        $file = $this->voice;
        $this->voice = null;

        if ($file === null) {
            return;
        }

        // A gravação nasceu em outra conversa (o atendente trocou de ticket no meio): nunca vai para a que está na tela.
        if ($ticketId !== $ticket->id) {
            $file->delete();

            return;
        }

        $error = AttachmentRules::validate($file, true);

        if ($error === null && ! app(AgentRateLimits::class)->reserveSends($this->user(), 1)) {
            $error = 'Você está enviando mensagens rápido demais. Aguarde alguns segundos.';
        }

        if ($error === null) {
            $error = $this->storeVoice($ticket, $file);
        }

        if ($error !== null) {
            $this->addError('voice', $error);
        }

        $file->delete();
        $this->dispatch('transcript-refresh');
    }

    private function storeVoice(Ticket $ticket, TemporaryUploadedFile $file): ?string
    {
        try {
            app(SendTicketMedia::class)($ticket, $this->user(), $file, '', true);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function validateDraft(): void
    {
        // Sem arquivo o texto é obrigatório; com arquivo ele é só a legenda.
        $this->validate(
            ['draft' => [$this->attachments === [] ? 'required' : 'nullable', 'string', 'max:'.SendComposedMessage::MESSAGE_LIMIT]],
            ['draft.required' => 'Escreva uma mensagem.', 'draft.max' => 'A mensagem passa de '.SendComposedMessage::MESSAGE_LIMIT.' caracteres.'],
        );
    }

    private function discardAttachments(): void
    {
        foreach ($this->attachments as $file) {
            $file->delete();
        }

        $this->reset('attachments', 'upload');
    }

    private function reserveSends(int $count): bool
    {
        return app(AgentRateLimits::class)->reserveSends($this->user(), $count);
    }
}
