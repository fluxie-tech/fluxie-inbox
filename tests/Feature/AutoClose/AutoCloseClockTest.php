<?php

namespace Tests\Feature\AutoClose;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Models\BotSession;
use App\Models\BusinessHour;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\AutoClose\AutoCloseClock;
use App\Services\AutoClose\AutoCloseStep;
use App\Settings\AutoCloseSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoCloseClockTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Channel::factory()->create();
    }

    /** Sexta 27/11/2026, sábado 28, domingo 29 e segunda 30, no horário de São Paulo; o banco guarda em UTC. */
    private function at(string $dateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($dateTime, 'America/Sao_Paulo')->utc();
    }

    /** Segunda a sexta, das 8h às 18h. */
    private function schedule(): void
    {
        foreach ([1, 2, 3, 4, 5] as $weekday) {
            BusinessHour::create(['weekday' => $weekday, 'opens_at' => '08:00', 'closes_at' => '18:00']);
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function ticket(array $attributes = []): Ticket
    {
        return Ticket::factory()->create([
            'channel_id' => $this->channel->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function ours(Ticket $ticket, CarbonImmutable $sentAt, array $attributes = []): Message
    {
        return Message::factory()->create([
            'ticket_id' => $ticket->id,
            'sent_at' => $sentAt,
            'direction' => MessageDirection::OUT,
            'origin' => MessageOrigin::PAINEL,
            ...$attributes,
        ]);
    }

    private function step(Ticket $ticket, CarbonImmutable $now, bool $canMessage = true): AutoCloseStep
    {
        return (new AutoCloseClock)->step($ticket->fresh() ?? $ticket, $now, $canMessage);
    }

    public function test_padrao_encerra_em_24h_sem_aviso(): void
    {
        $ticket = $this->ticket();
        $sent = $this->at('2026-11-27 10:00');
        $this->ours($ticket, $sent);

        $this->assertSame(AutoCloseStep::NONE, $this->step($ticket, $sent->addMinutes(1439)));
        $this->assertSame(AutoCloseStep::CLOSE, $this->step($ticket, $sent->addMinutes(1440)));
    }

    public function test_desligado_nada(): void
    {
        AutoCloseSettings::save(['enabled' => false]);
        $ticket = $this->ticket();
        $sent = $this->at('2026-11-27 10:00');
        $this->ours($ticket, $sent);

        $this->assertSame(AutoCloseStep::NONE, $this->step($ticket, $sent->addDays(10)));
    }

    public function test_aguardando_nunca_entra(): void
    {
        $ticket = $this->ticket(['status' => TicketStatus::AGUARDANDO]);
        $sent = $this->at('2026-11-27 10:00');
        $this->ours($ticket, $sent);

        $this->assertSame(AutoCloseStep::NONE, $this->step($ticket, $sent->addDays(10)));
    }

    public function test_pendente_com_prazo_da_automacao_fica_de_fora_e_sem_prazo_entra(): void
    {
        $sent = $this->at('2026-11-27 10:00');
        $waiting = $this->ticket(['status' => TicketStatus::PENDENTE]);
        $this->ours($waiting, $sent, ['origin' => MessageOrigin::BOT]);
        BotSession::factory()->create(['ticket_id' => $waiting->id, 'expires_at' => $sent->addDay()]);

        $free = $this->ticket(['status' => TicketStatus::PENDENTE]);
        $this->ours($free, $sent, ['origin' => MessageOrigin::BOT]);
        BotSession::factory()->create(['ticket_id' => $free->id, 'expires_at' => null]);

        $this->assertSame(AutoCloseStep::NONE, $this->step($waiting, $sent->addDays(10)));
        $this->assertSame(AutoCloseStep::CLOSE, $this->step($free, $sent->addDays(10)));
    }

    public function test_ultima_do_cliente_nada_e_resposta_automatica_nao_conta(): void
    {
        $sent = $this->at('2026-11-27 10:00');

        $client = $this->ticket();
        $this->ours($client, $sent);
        Message::factory()->create(['ticket_id' => $client->id, 'sent_at' => $sent->addMinutes(5)]);
        $this->ours($client, $sent->addMinutes(6), ['origin' => MessageOrigin::BOT, 'auto_reply' => true]);

        $auto = $this->ticket();
        $this->ours($auto, $sent);
        $this->ours($auto, $sent->addHours(5), ['origin' => MessageOrigin::BOT, 'auto_reply' => true]);

        $this->assertSame(AutoCloseStep::NONE, $this->step($client, $sent->addDays(10)));
        $this->assertSame(AutoCloseStep::NONE, $this->step($this->ticket(), $sent->addDays(10)));
        $this->assertSame(AutoCloseStep::CLOSE, $this->step($auto, $sent->addMinutes(1440)));
    }

    public function test_aviso_sai_no_prazo_e_so_no_horario(): void
    {
        $this->schedule();
        AutoCloseSettings::save(['warn_enabled' => true, 'idle_minutes' => 120]);
        $ticket = $this->ticket();
        $this->ours($ticket, $this->at('2026-11-27 10:00'));

        $this->assertSame(AutoCloseStep::NONE, $this->step($ticket, $this->at('2026-11-27 11:59')));
        $this->assertSame(AutoCloseStep::WARN, $this->step($ticket, $this->at('2026-11-27 12:00')));

        // Venceu às 18h30, fora do expediente: o aviso espera abrir.
        $late = $this->ticket();
        $this->ours($late, $this->at('2026-11-27 16:30'));
        $this->assertSame(AutoCloseStep::NONE, $this->step($late, $this->at('2026-11-27 19:00')));
        $this->assertSame(AutoCloseStep::NONE, $this->step($late, $this->at('2026-11-28 10:00')));
        $this->assertSame(AutoCloseStep::WARN, $this->step($late, $this->at('2026-11-30 08:00')));
    }

    public function test_encerra_y_depois_do_aviso_e_marca_velha_nao_vale(): void
    {
        AutoCloseSettings::save(['warn_enabled' => true, 'idle_minutes' => 60, 'close_after_warn_minutes' => 30]);
        $ticket = $this->ticket();
        $sent = $this->at('2026-11-27 10:00');
        $this->ours($ticket, $sent);

        // A marca é anterior à última mensagem nossa: não vale, o aviso volta a ser devido.
        $ticket->forceFill(['auto_close_warned_at' => $sent->subMinutes(10)])->save();
        $this->assertSame(AutoCloseStep::WARN, $this->step($ticket, $sent->addMinutes(61)));

        $ticket->forceFill(['auto_close_warned_at' => $sent->addMinutes(61)])->save();
        $this->assertSame(AutoCloseStep::NONE, $this->step($ticket, $sent->addMinutes(90)));
        $this->assertSame(AutoCloseStep::CLOSE, $this->step($ticket, $sent->addMinutes(91)));
    }

    public function test_so_horario_de_atendimento_pula_o_fim_de_semana(): void
    {
        $this->schedule();
        AutoCloseSettings::save(['warn_enabled' => true, 'idle_minutes' => 120, 'business_hours_only' => true]);
        $ticket = $this->ticket();
        $this->ours($ticket, $this->at('2026-11-27 17:00'));

        // Sexta 17h + 2h de expediente: 1h na sexta e 1h na segunda, que abre às 8h.
        $this->assertSame(AutoCloseStep::NONE, $this->step($ticket, $this->at('2026-11-28 17:00')));
        $this->assertSame(AutoCloseStep::NONE, $this->step($ticket, $this->at('2026-11-30 08:59')));
        $this->assertSame(AutoCloseStep::WARN, $this->step($ticket, $this->at('2026-11-30 09:00')));

        // Y também conta só o expediente, a partir do aviso.
        AutoCloseSettings::save(['close_after_warn_minutes' => 120]);
        $ticket->forceFill(['auto_close_warned_at' => $this->at('2026-11-30 09:00')])->save();
        $this->assertSame(AutoCloseStep::NONE, $this->step($ticket, $this->at('2026-11-30 10:59')));
        $this->assertSame(AutoCloseStep::CLOSE, $this->step($ticket, $this->at('2026-11-30 11:00')));
    }

    public function test_despedida_ligada_espera_o_horario_e_desligada_encerra_a_qualquer_hora(): void
    {
        $this->schedule();
        $ticket = $this->ticket();
        $this->ours($ticket, $this->at('2026-11-27 16:00'));
        $saturday = $this->at('2026-11-28 16:00');

        $this->assertSame(AutoCloseStep::CLOSE, $this->step($ticket, $saturday));

        AutoCloseSettings::save(['farewell_enabled' => true]);
        $this->assertSame(AutoCloseStep::NONE, $this->step($ticket, $saturday));
        $this->assertSame(AutoCloseStep::CLOSE, $this->step($ticket, $this->at('2026-11-30 08:00')));

        // Depois do aviso o encerramento também espera o horário.
        AutoCloseSettings::save(['warn_enabled' => true, 'close_after_warn_minutes' => 15]);
        $ticket->forceFill(['auto_close_warned_at' => $this->at('2026-11-28 08:00')])->save();
        $this->assertSame(AutoCloseStep::NONE, $this->step($ticket, $saturday));
        $this->assertSame(AutoCloseStep::CLOSE, $this->step($ticket, $this->at('2026-11-30 08:00')));
    }

    public function test_canal_parado_encerra_em_x_sem_aviso(): void
    {
        $this->schedule();
        AutoCloseSettings::save(['warn_enabled' => true, 'farewell_enabled' => true]);
        $ticket = $this->ticket();
        $this->ours($ticket, $this->at('2026-11-27 16:00'));
        $saturday = $this->at('2026-11-28 16:00');

        $this->assertSame(AutoCloseStep::NONE, $this->step($ticket, $saturday->subMinute(), false));
        $this->assertSame(AutoCloseStep::CLOSE, $this->step($ticket, $saturday, false));
        $this->assertSame(AutoCloseStep::NONE, $this->step($ticket, $saturday));
    }

    public function test_marca_valida_com_canal_parado_encerra_em_y_mesmo_fora_do_horario_e_com_despedida_ligada(): void
    {
        $this->schedule();
        AutoCloseSettings::save(['warn_enabled' => true, 'farewell_enabled' => true, 'close_after_warn_minutes' => 15]);
        $ticket = $this->ticket();
        $this->ours($ticket, $this->at('2026-11-27 16:00'));
        $warned = $this->at('2026-11-28 10:00');
        $ticket->forceFill(['auto_close_warned_at' => $warned])->save();

        $this->assertSame(AutoCloseStep::NONE, $this->step($ticket, $warned->addMinutes(14), false));
        $this->assertSame(AutoCloseStep::CLOSE, $this->step($ticket, $warned->addMinutes(15), false));

        AutoCloseSettings::save(['farewell_enabled' => false]);
        $this->assertSame(AutoCloseStep::CLOSE, $this->step($ticket, $warned->addMinutes(15), false));
    }

    public function test_marca_igual_ao_envio_da_ultima_nao_vale(): void
    {
        AutoCloseSettings::save(['warn_enabled' => true, 'idle_minutes' => 60, 'close_after_warn_minutes' => 15]);
        $ticket = $this->ticket();
        $sent = $this->at('2026-11-27 10:00');
        $this->ours($ticket, $sent);
        $ticket->forceFill(['auto_close_warned_at' => $sent])->save();

        $this->assertSame(AutoCloseStep::NONE, $this->step($ticket, $sent->addMinutes(59)));
        $this->assertSame(AutoCloseStep::WARN, $this->step($ticket, $sent->addMinutes(60)));
    }

    public function test_o_fuso_do_now_passado_nao_muda_o_resultado(): void
    {
        $this->schedule();
        AutoCloseSettings::save(['warn_enabled' => true, 'idle_minutes' => 120, 'business_hours_only' => true]);
        $ticket = $this->ticket();
        $this->ours($ticket, $this->at('2026-11-27 17:00'));

        foreach (['2026-11-30 08:59' => AutoCloseStep::NONE, '2026-11-30 09:00' => AutoCloseStep::WARN] as $local => $expected) {
            $now = $this->at($local);

            $this->assertSame($expected, $this->step($ticket, $now));
            $this->assertSame($expected, $this->step($ticket, $now->setTimezone('America/Sao_Paulo')));
        }
    }

    public function test_aviso_desligado_depois_da_marca_ainda_encerra_em_y(): void
    {
        AutoCloseSettings::save(['warn_enabled' => false, 'idle_minutes' => 60, 'close_after_warn_minutes' => 30]);
        $ticket = $this->ticket();
        $sent = $this->at('2026-11-27 10:00');
        $this->ours($ticket, $sent);
        $warned = $sent->addMinutes(61);
        $ticket->forceFill(['auto_close_warned_at' => $warned])->save();

        $this->assertSame(AutoCloseStep::NONE, $this->step($ticket, $warned->addMinutes(29)));
        $this->assertSame(AutoCloseStep::CLOSE, $this->step($ticket, $warned->addMinutes(30)));
    }
}
