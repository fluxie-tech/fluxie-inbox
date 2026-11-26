<?php

namespace App\Livewire\Inbox;

use App\Actions\Inbox\ClaimTicket;
use App\Actions\Inbox\OpenTicketForAgent;
use App\Actions\Inbox\TakeoverTicket;
use App\Livewire\Inbox\Concerns\ActsOnMessages;
use App\Livewire\Inbox\Concerns\ComposesMessages;
use App\Livewire\Inbox\Concerns\TakesNotes;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\ResendTicketMessage;
use App\Services\Inbox\TicketLookup;
use App\Services\Inbox\TicketPanel;
use App\Services\Inbox\TicketQueueQuery;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Atendimento')]
#[Layout('layouts::inbox')]
class Inbox extends Component
{
    use ActsOnMessages;
    use ComposesMessages;
    use TakesNotes;

    private const ALREADY_CLOSED = 'Este ticket já foi encerrado por outra pessoa.';

    public ?int $ticketId = null;

    /** O ticket que estava aberto antes do atual, para o botão "Voltar". */
    public ?int $previousTicketId = null;

    #[Url]
    public string $tab = 'todos';

    /** Busca da Fila por nome, telefone ou protocolo (a lupa do cabeçalho); vazia não filtra. */
    public string $search = '';

    /** Filtro da lista por etiqueta. */
    #[Url]
    public ?int $tagId = null;

    public ?string $notice = null;

    /** Painel aberto sobre a conversa: `transfer`, `reassign` ou nenhum. */
    public ?string $panel = null;

    public ?int $transferSectorId = null;

    public string $transferNote = '';

    public ?int $reassignUserId = null;

    public string $closeReasonId = '';

    public string $closeSummary = '';

    /** Link de outra tela (`/inbox?ticket=ID`): abre o ticket, mas só se o usuário puder vê-lo. */
    public function mount(): void
    {
        $id = request()->integer('ticket');

        if ($id > 0 && $this->lookup()->canSee($this->user(), $id)) {
            $this->select($id);

            // Vindo da janela "Nova conversa" com um ticket que já existia: o aviso e, se pode responder, o texto digitado.
            $handoff = $this->lookup()->handoff($this->user(), $id);
            $this->notice = $handoff['notice'];
            $this->draft = $handoff['draft'] ?? $this->draft;
        }
    }

    public function select(int $ticketId): void
    {
        $ticket = Ticket::findOrFail($ticketId);
        $this->authorize('view', $ticket);

        app(OpenTicketForAgent::class)($ticket, $this->user());

        if ($this->ticketId !== $ticketId) {
            // Abrir outro ticket (um encerrado, por exemplo) não pode fazer o atual sumir sem volta.
            $this->previousTicketId = $this->ticketId ?? $this->previousTicketId;
            // O campo é um só: o texto de um ticket não pode aparecer (nem ser enviado) em outro. O rascunho fica no navegador.
            $this->reset('draft', 'showQuickReplies');
        }

        $this->ticketId = $ticketId;
        $this->notice = null;
        $this->replyToId = null;
        $this->discardAttachments();
        $this->closePanel();
    }

    /** Reabre o ticket que estava na tela antes; o id vem do navegador, então só abre o que o usuário ainda pode ver. */
    public function goBack(): void
    {
        $id = $this->previousTicketId;
        $this->previousTicketId = null;

        if ($id !== null && $this->lookup()->canSee($this->user(), $id)) {
            $this->select($id);
            $this->previousTicketId = null;
        }
    }

    public function claim(): void
    {
        // Sem o filtro de visibilidade: quem perde a corrida ainda precisa receber o aviso, não um 404.
        $ticket = Ticket::findOrFail($this->ticketId);

        if (! $this->user()->can('claim', $ticket) || ! (new ClaimTicket)($ticket, $this->user())) {
            $this->notice = 'Outra pessoa assumiu este ticket antes de você.';
        }
    }

    /**
     * `$seenAssignee` é o responsável que estava na tela: se o ticket mudou de mão no meio do caminho, a troca
     * não acontece e o usuário recebe o aviso.
     */
    public function takeover(int $seenAssignee): void
    {
        $ticket = $this->selected();
        $this->authorize('takeover', $ticket);

        $this->notice = (new TakeoverTicket)($ticket, $this->user(), $seenAssignee)
            ? null
            : 'Este ticket mudou de responsável antes de você tomá-lo.';
    }

    public function openPanel(string $panel): void
    {
        $ticket = $this->selected();
        $panel = in_array($panel, ['reassign', 'close'], true) ? $panel : 'transfer';
        $this->authorize($panel, $ticket);

        $this->panel = $panel;
        $this->resetErrorBag();
    }

    public function closePanel(): void
    {
        $this->reset('panel', 'transferSectorId', 'transferNote', 'reassignUserId', 'closeReasonId', 'closeSummary');
        $this->resetErrorBag();
    }

    public function transfer(): void
    {
        $ticket = $this->selected();
        $this->authorize('transfer', $ticket);

        $this->validate(TicketPanel::transferRules(), TicketPanel::TRANSFER_MESSAGES);

        $moved = $this->ticketPanel()->transfer($ticket, $this->user(), (int) $this->transferSectorId, $this->transferNote);

        $this->notice = $moved ? null : 'Não foi possível transferir: o ticket já está nesse setor ou foi encerrado.';
        $this->closePanel();
    }

    public function reassign(): void
    {
        $ticket = $this->selected();
        $this->authorize('reassign', $ticket);

        $this->validate(TicketPanel::reassignRules(), TicketPanel::REASSIGN_MESSAGES);

        $reassigned = $this->ticketPanel()->reassign($ticket, $this->user(), (int) $this->reassignUserId);

        $this->notice = $reassigned ? null : 'Não foi possível reatribuir: essa pessoa não atende este setor ou já está com o ticket.';
        $this->closePanel();
    }

    public function close(): void
    {
        $ticket = $this->selected();

        // Outra pessoa encerrou enquanto o painel estava aberto: aviso, não erro 403.
        if ($this->ticketPanel()->alreadyClosed($ticket)) {
            $this->notice = self::ALREADY_CLOSED;
            $this->closePanel();

            return;
        }

        $this->authorize('close', $ticket);

        $this->closeSummary = trim($this->closeSummary);
        $this->validate(TicketPanel::closeRules((int) $this->closeReasonId), TicketPanel::CLOSE_MESSAGES);

        $closed = $this->ticketPanel()->close($ticket, $this->user(), (int) $this->closeReasonId, $this->closeSummary);

        $this->notice = $closed ? null : self::ALREADY_CLOSED;
        $this->closePanel();
    }

    /**
     * Reenvia, na mesma linha, uma mensagem que falhou. Falha que pode ter saído exige confirmação (o botão pede e chama de
     * novo com `$confirmed`); o servidor confere de novo, porque a tela sozinha não protege contra mensagem em dobro.
     */
    public function resend(int $messageId, bool $confirmed = false): void
    {
        $ticket = $this->selected();
        $this->authorize('reply', $ticket);

        $result = (new ResendTicketMessage)->forTicket($ticket, $messageId, $this->user(), $confirmed);

        $this->notice = match ($result) {
            ResendTicketMessage::CONFIRM => 'Confirme antes de reenviar: a mensagem pode já ter sido enviada.',
            ResendTicketMessage::NONE => 'Esta mensagem não pode ser reenviada.',
            default => null,
        };

        if ($result === 'sent') {
            $this->dispatch('transcript-refresh');
        }
    }

    /**
     * Qualquer evento do inbox só pede nova leitura do banco; o texto nunca viaja pelo websocket.
     */
    #[On('echo-private:inbox,.ticket.updated')]
    #[On('echo-private:inbox,.message.created')]
    #[On('echo-private:inbox,.channel.status')]
    #[On('realtime-reconnected')]
    // As etiquetas mudaram no painel (ficha do contato ou etiquetas da conversa): o card da fila se atualiza na hora, sem esperar o websocket.
    #[On('tags-changed')]
    public function refresh(): void {}

    private function user(): User
    {
        $user = auth()->user();
        assert($user instanceof User);

        return $user;
    }

    private function lookup(): TicketLookup
    {
        return app(TicketLookup::class);
    }

    private function ticketPanel(): TicketPanel
    {
        return app(TicketPanel::class);
    }

    /**
     * O ticket aberto na tela, desde que o usuário ainda possa vê-lo (outro atendente pode ter assumido).
     */
    private function selected(): Ticket
    {
        return $this->lookup()->selected($this->user(), $this->ticketId);
    }

    public function render(): View
    {
        $user = $this->user();
        $ticket = $this->lookup()->detailed($user, $this->ticketId);
        $panel = $this->ticketPanel();

        return view('livewire.inbox.inbox', [
            ...app(TicketQueueQuery::class)->snapshot($user, $this->tab, $this->tagId, $this->search, $ticket),
            'ticket' => $ticket,
            'replyTo' => $this->replyBar($ticket?->id),
            'previous' => $this->lookup()->previous($user, $this->previousTicketId),
            'canClaim' => $ticket !== null && $user->can('claim', $ticket),
            'canClose' => $ticket !== null && $user->can('close', $ticket),
            'canReply' => $ticket !== null && $user->can('reply', $ticket),
            'canTransfer' => $ticket !== null && $user->can('transfer', $ticket),
            'canReassign' => $ticket !== null && $user->can('reassign', $ticket),
            'canTakeover' => $ticket !== null && $user->can('takeover', $ticket),
            'sectors' => $this->panel === 'transfer' ? $panel->sectors() : collect(),
            ...$panel->closeForm($this->panel, (int) $this->closeReasonId),
            'candidates' => $ticket !== null && $this->panel === 'reassign' ? $panel->candidates($ticket) : collect(),
        ]);
    }
}
