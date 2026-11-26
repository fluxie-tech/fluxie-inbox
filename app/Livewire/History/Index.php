<?php

namespace App\Livewire\History;

use App\Enums\TicketStatus;
use App\Models\CloseReason;
use App\Models\Contact;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\HistorySearch;
use App\Services\Inbox\HistoryTicketView;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Busca e leitura dos tickets encerrados que o usuário pode ver. Só leitura: nada aqui altera ticket. */
#[Title('Histórico')]
#[Layout('layouts::inbox')]
class Index extends Component
{
    use WithPagination;

    private const PER_PAGE = 25;

    private const DEFAULT_DAYS = 30;

    #[Url(as: 'q')]
    public string $q = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public ?int $sectorId = null;

    #[Url]
    public ?int $userId = null;

    #[Url]
    public string $outcome = '';

    #[Url]
    public ?int $contactId = null;

    #[Url]
    public ?int $ticketId = null;

    /** O `De` padrão, fixado na abertura da tela (virar o dia com ela aberta não faz o padrão parecer um filtro). Vazio quando há contato. */
    #[Locked]
    public string $defaultFrom = '';

    public function mount(): void
    {
        // Vindo do "Ver todos" de um contato, o que interessa são os antigos: sem a janela padrão.
        $this->defaultFrom = $this->contactId === null
            ? CarbonImmutable::now(config()->string('app.display_timezone'))->subDays(self::DEFAULT_DAYS)->toDateString()
            : '';

        if ($this->from === '') {
            $this->from = $this->defaultFrom;
        }
    }

    /** Volta os filtros do popover ao padrão: o período padrão e nenhuma data final, setor, motivo ou atendente. A busca e o contato filtrado ficam. */
    public function clearFilters(): void
    {
        $this->from = $this->defaultFrom;
        $this->reset('to', 'sectorId', 'outcome', 'userId');
        $this->resetPage();
    }

    /** Quantos filtros do popover estão fora do padrão (o `De` padrão não conta). */
    private function activeFilters(): int
    {
        return count(array_filter([
            $this->from !== $this->defaultFrom,
            $this->to !== '',
            $this->sectorId !== null,
            $this->userId !== null,
            $this->closeReasonId() !== null,
        ]));
    }

    /** Só id vale: a URL antiga com o slug do desfecho (`resolvido`) abre sem filtro. */
    private function closeReasonId(): ?int
    {
        return ctype_digit($this->outcome) ? (int) $this->outcome : null;
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['q', 'from', 'to', 'sectorId', 'userId', 'outcome', 'contactId'], true)) {
            $this->resetPage();
        }
    }

    public function select(int $ticketId): void
    {
        $ticket = Ticket::query()->findOrFail($ticketId);

        $this->authorize('view', $ticket);
        abort_unless($ticket->status === TicketStatus::ENCERRADO, 404);

        $this->ticketId = $ticket->id;
    }

    public function render(HistoryTicketView $ticketView): View
    {
        $user = auth()->user();
        assert($user instanceof User);

        $tickets = (new HistorySearch)
            ->query($user, $this->q, $this->from ?: null, $this->to ?: null, $this->sectorId, $this->userId, $this->closeReasonId(), $this->contactId)
            ->with(['contact.tags', 'sector', 'assignee', 'closeReason'])
            ->simplePaginate(self::PER_PAGE);

        return view('livewire.history.index', [
            'activeFilters' => $this->activeFilters(),
            'tickets' => $tickets,
            ...$ticketView->for($user, $this->ticketId),
            // Setor apagado que já teve ticket continua filtrável; o que nunca teve não polui a lista.
            'sectors' => Sector::withTrashed()
                ->where(fn ($query) => $query->whereNull('deleted_at')->orWhereIn('id', Ticket::query()->select('sector_id')->whereNotNull('sector_id')))
                ->orderBy('name')->get(['id', 'name', 'deleted_at']),
            'agents' => $user->canSeeAllSectors() ? User::query()->orderBy('name')->get(['id', 'name']) : null,
            'outcomes' => CloseReason::query()->ordered()->get(),
            'filteredContact' => $this->contactId === null ? null : Contact::query()->find($this->contactId)?->label(),
        ]);
    }
}
