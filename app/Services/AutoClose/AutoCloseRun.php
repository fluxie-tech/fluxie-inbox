<?php

namespace App\Services\AutoClose;

use App\Actions\Inbox\CloseTicket;
use App\Enums\ClosedReason;
use App\Models\Ticket;
use App\Services\Inbox\BusinessHours;
use App\Settings\AutoCloseSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Uma rodada do encerramento automático: avisa e encerra os chamados parados, até o limite de avisos e de despedidas por rodada.
 * O relógio (`AutoCloseClock`) decide; aqui estão o travamento, a marca do aviso, o envio e o encerramento.
 */
class AutoCloseRun
{
    public const MAX_WARNINGS = 30;

    public const MAX_FAREWELLS = 30;

    public function __construct(
        private readonly AutoCloseCandidates $candidates,
        private readonly LastCountingMessage $last,
        private readonly AutoCloseMessages $messages,
        private readonly CloseTicket $close,
    ) {}

    /** @return array{warned: int, closed: int} */
    public function __invoke(): array
    {
        $count = ['warned' => 0, 'closed' => 0];

        if (! AutoCloseSettings::enabled()) {
            return $count;
        }

        $now = now()->toImmutable();
        // As faixas e os feriados são lidos uma vez para a rodada inteira, não a cada chamado.
        $clock = new AutoCloseClock((new BusinessHours)->snapshot(), $this->last);
        $farewells = 0;

        foreach ($this->candidates->ids($now) as $id) {
            // Não corre contra uma resposta que o job do bot está processando agora.
            $lock = Cache::lock('bot-ticket-'.$id, 120);

            if (! $lock->get()) {
                continue;
            }

            try {
                $done = $this->decide($id, $clock, $now, $count['warned'] < self::MAX_WARNINGS, $farewells < self::MAX_FAREWELLS);
                $sent = $done !== null && $this->messages->send($done[0], $done[1]);
            } finally {
                $lock->release();
            }

            if ($done !== null) {
                $warning = $done[0] === AutoCloseStep::WARN;
                $count[$warning ? 'warned' : 'closed']++;
                $farewells += ! $warning && $sent ? 1 : 0;
            }
        }

        return $count;
    }

    /**
     * Com a linha travada, confere de novo o que o relógio manda e aplica: grava a marca do aviso ou encerra. O envio fica
     * para depois da transação. Devolve o passo dado e o ticket, ou nulo se nada foi feito.
     *
     * @return array{AutoCloseStep, Ticket}|null
     */
    private function decide(int $id, AutoCloseClock $clock, CarbonImmutable $now, bool $canWarn, bool $canFarewell): ?array
    {
        return DB::transaction(function () use ($id, $clock, $now, $canWarn, $canFarewell): ?array {
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
            $waitsForFarewell = $canMessage && AutoCloseSettings::farewellEnabled() && ! $canFarewell;

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
