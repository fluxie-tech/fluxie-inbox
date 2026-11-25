<?php

namespace App\Services\Inbox;

use App\Enums\ClosedReason;
use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/** Tudo o que a Fila lê do banco para se desenhar: a lista aberta, as abas, os encerrados recentes, a presença do cliente e as etiquetas. */
final class TicketQueueQuery
{
    /** Prévia da lista: o texto ou, quando não há, o nome do tipo de conteúdo. */
    private const PREVIEW_SQL = "case when revoked_at is not null then 'Apagada · ' else '' end || "
        ."case when type = 'location' then 'Localização' when type = 'contact' then 'Contato' "
        ."when body <> '' then body when type = 'view_once' then 'Visualização única' "
        ."when media_mime = 'image/webp' then 'Figurinha' when media_mime like 'image/%' then 'Foto' "
        ."when media_mime like 'audio/%' then 'Áudio' when media_mime like 'video/%' then 'Vídeo' "
        ."when type = 'media' then 'Arquivo' else '['||type||']' end";

    /** A aba "Encerrados" mostra só o recente; a busca completa fica no Histórico. */
    private const CLOSED_TAB_DAYS = 7;

    private const CLOSED_TAB_LIMIT = 50;

    /**
     * As chaves que a view da Fila espera (contactTags, ticketTags, pending, waiting, active, closed, clientPresence, tabCounts,
     * summary, showSector, showChannel). A lista aberta é lida uma vez e reaproveitada pela presença e pelo setor.
     *
     * @return array<string, mixed>
     */
    public function snapshot(User $user, string $tab, ?int $tagId, string $search, ?Ticket $ticket): array
    {
        $list = $this->open($user, $tab, $tagId, $search);
        $counts = $this->counts($user);

        return [
            'contactTags' => Tag::sortedByName(Tag::query()->ofContact()->get()),
            'ticketTags' => Tag::sortedByName(Tag::query()->ofTicket()->get()),
            'pending' => $list->where('status', TicketStatus::PENDENTE),
            'waiting' => $list->where('status', TicketStatus::AGUARDANDO),
            'active' => $list->where('status', TicketStatus::EM_ATENDIMENTO),
            'closed' => $this->recentlyClosed($user, $tab, $search),
            // "Digitando…" do cliente guardado no Redis: quem abre a tela no meio da digitação já o vê (os avisos seguintes vêm pelo Reverb).
            'clientPresence' => $this->clientPresence($list, $ticket),
            'tabCounts' => $counts,
            // Sem ticket aberto a coluna do meio mostra o resumo do dia (ver `summary`).
            'summary' => QueueOverview::summary($ticket, $user, $counts),
            'showSector' => QueueOverview::showSector($list),
            // O selo do número só ajuda quando há mais de um número ativo.
            'showChannel' => Channel::query()->where('active', true)->count() >= 2,
        ];
    }

    /** @return EloquentCollection<int, Ticket> */
    private function open(User $user, string $tab, ?int $tagId, string $search): EloquentCollection
    {
        return Ticket::query()
            ->open()
            ->visibleTo($user)
            ->with(['contact.tags', 'channel', 'tags', 'assignee', 'sector'])
            ->tap(TicketQueue::withUnread(...))
            ->addSelect(['preview' => Message::query()
                ->selectRaw(self::PREVIEW_SQL)->whereColumn('ticket_id', 'tickets.id')->orderByDesc('sent_at')->orderByDesc('id')->limit(1)])
            ->when($tab === 'pendentes', fn (Builder $q) => $q->where('status', TicketStatus::PENDENTE->value))
            ->when($tab === 'aguardando', fn (Builder $q) => $q->where('status', TicketStatus::AGUARDANDO->value))
            ->when($tab === 'meus', fn (Builder $q) => $q->where('assigned_user_id', $user->id))
            ->when($tagId !== null, fn (Builder $q) => TicketQueue::withTag($q, (int) $tagId))
            ->tap(fn (Builder $q) => TicketTextSearch::apply($q, $search))
            ->orderBy('last_inbound_at')
            ->get();
    }

    /** @return array{pendentes: int, aguardando: int, meus: int} */
    private function counts(User $user): array
    {
        return [
            'pendentes' => Ticket::query()->open()->visibleTo($user)->where('status', TicketStatus::PENDENTE->value)->count(),
            'aguardando' => Ticket::query()->open()->visibleTo($user)->where('status', TicketStatus::AGUARDANDO->value)->count(),
            'meus' => Ticket::query()->open()->where('assigned_user_id', $user->id)->count(),
        ];
    }

    /**
     * Os encerrados recentes que o usuário pode ver, para a aba "Encerrados"; vazio nas outras abas.
     *
     * @return EloquentCollection<int, Ticket>
     */
    private function recentlyClosed(User $user, string $tab, string $search): EloquentCollection
    {
        if ($tab !== 'encerrados') {
            return new EloquentCollection;
        }

        return Ticket::query()->inHistoryOf($user)->where('closed_at', '>=', now()->subDays(self::CLOSED_TAB_DAYS))
            ->where(fn (Builder $q) => $q->whereNull('closed_reason')->orWhere('closed_reason', '<>', ClosedReason::IMPORTADO->value))
            ->tap(fn (Builder $q) => TicketTextSearch::apply($q, $search))
            ->with('contact')->orderByDesc('closed_at')->orderByDesc('id')->limit(self::CLOSED_TAB_LIMIT)->get();
    }

    /**
     * @param  EloquentCollection<int, Ticket>  $list
     * @return array<int, string>
     */
    private function clientPresence(EloquentCollection $list, ?Ticket $ticket): array
    {
        $ids = [...$list->modelKeys(), ...($ticket === null ? [] : [$ticket->id])];

        return app(ClientPresence::class)->states(array_values(array_unique(array_map(intval(...), $ids))));
    }
}
