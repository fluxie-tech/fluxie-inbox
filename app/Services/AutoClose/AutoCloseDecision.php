<?php

namespace App\Services\AutoClose;

use App\Actions\Inbox\CloseTicket;
use App\Enums\ClosedReason;
use App\Models\Ticket;
use App\Settings\AutoCloseSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** O que a rodada do encerramento automático faz com um chamado: confere de novo com a linha travada e grava a marca do aviso ou encerra. */
class AutoCloseDecision
{
    public function __construct(
        private readonly LastCountingMessage $last,
        private readonly AutoCloseMessages $messages,
        private readonly CloseTicket $close,
    ) {}

    /**
     * O envio fica para depois da transação. Devolve o passo dado e o ticket, ou nulo se nada foi feito.
     *
     * @return array{AutoCloseStep, Ticket}|null
     */
    public function __invoke(int $id, AutoCloseClock $clock, AutoCloseSnapshot $config, CarbonImmutable $now, bool $canWarn, bool $canFarewell): ?array
    {
        return DB::transaction(function () use ($id, $clock, $config, $now, $canWarn, $canFarewell): ?array {
            $ticket = Ticket::query()->lockForUpdate()->find($id);

            if ($ticket === null) {
                return null;
            }

            $canMessage = $this->messages->canMessage($ticket);
            $step = $clock->step($ticket, $now, $canMessage);

            if ($step === AutoCloseStep::WARN && $canWarn && $this->mark($ticket, $now)) {
                return [$step, $ticket];
            }

            // Com despedida ligada e o limite dela esgotado o chamado espera a próxima rodada, para não sair sem despedida.
            $waitsForFarewell = $canMessage && $config->farewellEnabled && ! $canFarewell;

            if ($step === AutoCloseStep::CLOSE && ! $waitsForFarewell && ($this->close)($ticket, null, ClosedReason::INATIVIDADE)) {
                return [$step, $ticket];
            }

            return null;
        });
    }

    /** Grava a marca do aviso uma única vez: só vale se ainda não há marca posterior à última mensagem que conta. */
    private function mark(Ticket $ticket, CarbonImmutable $now): bool
    {
        $last = $this->last->for($ticket);

        return $last !== null && Ticket::query()
            ->whereKey($ticket->id)
            ->where(fn ($query) => $query->whereNull('auto_close_warned_at')->orWhere('auto_close_warned_at', '<=', $last->sent_at))
            ->update(['auto_close_warned_at' => $now]) === 1;
    }
}
