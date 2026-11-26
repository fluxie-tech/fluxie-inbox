<?php

namespace App\Services\Inbox;

use App\Actions\Inbox\CloseTicket;
use App\Actions\Inbox\ReassignTicket;
use App\Actions\Inbox\TransferTicket;
use App\Enums\ClosedReason;
use App\Enums\SectorStatus;
use App\Enums\TicketStatus;
use App\Models\CloseReason;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Os painéis transferir, reatribuir e encerrar da conversa: regras de validação, mensagens, listas de opções e a ação de cada um.
 * Quem autoriza, valida e fecha o painel é a tela; aqui ficam as regras e o que acontece depois de validar.
 */
final class TicketPanel
{
    public const TRANSFER_MESSAGES = [
        'transferSectorId.required' => 'Escolha o setor de destino.',
        'transferSectorId.exists' => 'Esse setor não recebe tickets.',
        'transferNote.max' => 'A nota passa de 500 caracteres.',
    ];

    public const REASSIGN_MESSAGES = [
        'reassignUserId.required' => 'Escolha quem fica com o ticket.',
    ];

    public const CLOSE_MESSAGES = [
        'closeReasonId.required' => 'Escolha o motivo do encerramento.',
        'closeReasonId.exists' => 'Escolha o motivo do encerramento.',
        'closeSummary.required' => 'Escreva um resumo do atendimento.',
        'closeSummary.min' => 'O resumo precisa de ao menos 3 caracteres.',
        'closeSummary.max' => 'O resumo passa de 500 caracteres.',
    ];

    /** @return array<string, list<mixed>> */
    public static function transferRules(): array
    {
        return [
            'transferSectorId' => ['required', 'integer', Rule::exists('sectors', 'id')->whereIn('status', self::receivingStatuses())],
            'transferNote' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function reassignRules(): array
    {
        return ['reassignUserId' => ['required', 'integer', Rule::exists('users', 'id')->where('active', true)]];
    }

    /** @return array<string, list<mixed>> */
    public static function closeRules(?int $closeReasonId): array
    {
        return [
            'closeReasonId' => ['required', 'integer', Rule::exists('close_reasons', 'id')->where('active', true)],
            'closeSummary' => [self::requiresSummary($closeReasonId) ? 'required' : 'nullable', 'string', 'min:3', 'max:500'],
        ];
    }

    /** O motivo ativo pede resumo? Motivo desativado ou desconhecido não pede (a regra do `closeReasonId` o recusa). */
    public static function requiresSummary(?int $closeReasonId): bool
    {
        return $closeReasonId !== null && CloseReason::query()->active()->whereKey($closeReasonId)->where('requires_summary', true)->exists();
    }

    public function transfer(Ticket $ticket, User $by, int $sectorId, string $note): bool
    {
        return (new TransferTicket)($ticket, $by, Sector::findOrFail($sectorId), $note);
    }

    public function reassign(Ticket $ticket, User $by, int $userId): bool
    {
        return (new ReassignTicket)($ticket, $by, User::findOrFail($userId));
    }

    public function close(Ticket $ticket, User $by, int $closeReasonId, string $summary): bool
    {
        return (new CloseTicket)($ticket, $by, ClosedReason::MANUAL, CloseReason::findOrFail($closeReasonId), $summary === '' ? null : $summary);
    }

    /** @return Collection<int, CloseReason> */
    public function closeReasons(): Collection
    {
        return CloseReason::query()->active()->ordered()->get();
    }

    /**
     * O que a view do painel Encerrar precisa: a lista de motivos (só com o painel aberto) e se o escolhido pede resumo.
     *
     * @return array{closeReasons: Collection<int, CloseReason>, summaryRequired: bool}
     */
    public function closeForm(?string $panel, int $closeReasonId): array
    {
        return $panel === 'close'
            ? ['closeReasons' => $this->closeReasons(), 'summaryRequired' => self::requiresSummary($closeReasonId)]
            : ['closeReasons' => new Collection, 'summaryRequired' => false];
    }

    /** Outra pessoa encerrou enquanto o painel estava aberto. */
    public function alreadyClosed(Ticket $ticket): bool
    {
        return $ticket->status === TicketStatus::ENCERRADO;
    }

    /** @return Collection<int, Sector> */
    public function sectors(): Collection
    {
        return Sector::query()->receivingTickets()->orderBy('menu_order')->get();
    }

    /**
     * Quem pode receber o ticket numa reatribuição: usuários ativos do setor dele (ou todos, se não tem setor).
     *
     * @return Collection<int, User>
     */
    public function candidates(Ticket $ticket): Collection
    {
        return User::query()
            ->where('active', true)
            ->when($ticket->sector_id !== null, fn (Builder $q) => $q->whereHas('sectors', fn (Builder $s) => $s->whereKey($ticket->sector_id)))
            ->when($ticket->assigned_user_id !== null, fn (Builder $q) => $q->whereKeyNot($ticket->assigned_user_id))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<string>
     */
    private static function receivingStatuses(): array
    {
        return array_values(array_map(
            fn (SectorStatus $status): string => $status->value,
            array_filter(SectorStatus::cases(), fn (SectorStatus $status): bool => $status->receivesTickets()),
        ));
    }
}
