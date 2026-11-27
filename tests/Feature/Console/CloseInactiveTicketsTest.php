<?php

namespace Tests\Feature\Console;

use App\Actions\Inbox\CloseTicket;
use App\Enums\ClosedReason;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Jobs\SendTicketMessageJob;
use App\Models\BotSession;
use App\Models\BusinessHour;
use App\Models\Channel;
use App\Models\CloseReason;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Settings\AutoCloseSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class CloseInactiveTicketsTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        // Sexta-feira 27/11/2026, 15h UTC.
        $this->travelTo(CarbonImmutable::parse('2026-11-27 15:00:00', 'UTC'));
        $this->channel = Channel::factory()->connected()->create();
    }

    /** @param  array<string, mixed>  $attributes */
    private function ticket(array $attributes = []): Ticket
    {
        return Ticket::factory()->create($attributes + [
            'channel_id' => $this->channel->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => User::factory(),
            'opened_at' => now()->subDays(5),
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function message(Ticket $ticket, MessageDirection $direction, int $minutesAgo, array $attributes = []): Message
    {
        return Message::factory()->create([
            'ticket_id' => $ticket->id,
            'direction' => $direction,
            'origin' => $direction === MessageDirection::OUT ? MessageOrigin::PAINEL : MessageOrigin::CONTATO,
            'sent_at' => now()->subMinutes($minutesAgo),
            ...$attributes,
        ]);
    }

    private function ours(Ticket $ticket, int $minutesAgo): Message
    {
        return $this->message($ticket, MessageDirection::OUT, $minutesAgo);
    }

    private function autoReplies(Ticket $ticket): int
    {
        return $ticket->messages()->where('auto_reply', true)->count();
    }

    public function test_esta_agendado_a_cada_minuto(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'app:close-inactive-tickets'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
    }

    public function test_padrao_encerra_parado_ha_24h_cuja_ultima_e_nossa_e_fecha_a_sessao_do_bot(): void
    {
        Queue::fake();
        $ticket = $this->ticket();
        $this->message($ticket, MessageDirection::IN, 30 * 60);
        $this->ours($ticket, 25 * 60);
        $session = BotSession::factory()->create(['ticket_id' => $ticket->id, 'state' => BotSession::ACTIVE, 'expires_at' => null]);

        $this->artisan('app:close-inactive-tickets')->expectsOutput('Avisos: 0. Encerrados: 1.')->assertSuccessful();

        $ticket->refresh();
        $this->assertSame(TicketStatus::ENCERRADO, $ticket->status);
        $this->assertSame(ClosedReason::INATIVIDADE, $ticket->closed_reason);
        $this->assertNull($ticket->close_reason_id);
        $this->assertNull($ticket->close_summary);
        $this->assertSame(BotSession::CLOSED, $session->fresh()?->state);

        $event = $ticket->events()->where('type', 'closed')->sole();
        $this->assertNull($event->actor_user_id);
        $this->assertSame(['reason' => 'inatividade'], $event->payload);
        Queue::assertNotPushed(SendTicketMessageJob::class);
    }

    public function test_nao_encerra_antes_de_24h(): void
    {
        $ticket = $this->ticket();
        $this->ours($ticket, 23 * 60);

        $this->artisan('app:close-inactive-tickets')->assertSuccessful();

        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $ticket->fresh()?->status);
    }

    public function test_nunca_encerra_quando_a_ultima_mensagem_e_do_cliente_por_mais_velha_que_seja(): void
    {
        $ticket = $this->ticket();
        $this->ours($ticket, 4 * 24 * 60);
        $this->message($ticket, MessageDirection::IN, 3 * 24 * 60);

        $this->artisan('app:close-inactive-tickets')->expectsOutput('Avisos: 0. Encerrados: 0.')->assertSuccessful();

        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $ticket->fresh()?->status);
    }

    public function test_chamado_aberto_pelo_celular_sem_mensagem_do_cliente_tambem_encerra(): void
    {
        $ticket = $this->ticket(['last_inbound_at' => null]);
        $this->ours($ticket, 26 * 60);

        $this->artisan('app:close-inactive-tickets')->assertSuccessful();

        $this->assertSame(TicketStatus::ENCERRADO, $ticket->fresh()?->status);
    }

    public function test_desligado_nao_avisa_nem_encerra(): void
    {
        AutoCloseSettings::save(['enabled' => false]);
        $ticket = $this->ticket();
        $this->ours($ticket, 40 * 60);

        $this->artisan('app:close-inactive-tickets')->expectsOutput('Avisos: 0. Encerrados: 0.')->assertSuccessful();

        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $ticket->fresh()?->status);
    }

    public function test_aviso_ligado_manda_o_aviso_marca_e_encerra_depois_de_y_com_despedida(): void
    {
        Queue::fake();
        AutoCloseSettings::save(['warn_enabled' => true, 'farewell_enabled' => true, 'idle_minutes' => 60, 'close_after_warn_minutes' => 30]);
        $ticket = $this->ticket();
        $this->ours($ticket, 61);

        $this->artisan('app:close-inactive-tickets')->expectsOutput('Avisos: 1. Encerrados: 0.')->assertSuccessful();

        $ticket->refresh();
        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $ticket->status);
        $this->assertNotNull($ticket->auto_close_warned_at);
        $this->assertSame(1, $this->autoReplies($ticket));
        Queue::assertPushed(SendTicketMessageJob::class, 1);
        $this->assertSame(1, $ticket->events()->where('type', 'auto_close_warned')->count());

        $this->travel(29)->minutes();
        $this->artisan('app:close-inactive-tickets')->expectsOutput('Avisos: 0. Encerrados: 0.')->assertSuccessful();
        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $ticket->fresh()?->status);

        $this->travel(2)->minutes();
        $this->artisan('app:close-inactive-tickets')->expectsOutput('Avisos: 0. Encerrados: 1.')->assertSuccessful();

        $this->assertSame(TicketStatus::ENCERRADO, $ticket->fresh()?->status);
        $this->assertSame(2, $this->autoReplies($ticket));
        Queue::assertPushed(SendTicketMessageJob::class, 2);
        $this->assertSame(1, $ticket->events()->where('type', 'auto_close_warned')->count());
        $this->assertSame(['reason' => 'inatividade', 'farewell' => true], $ticket->events()->where('type', 'closed')->sole()->payload);
        $this->assertStringContainsString('Encerramos o atendimento', (string) $ticket->messages()->where('auto_reply', true)->latest('id')->value('body'));
    }

    public function test_cliente_responde_ao_aviso_e_nao_encerra(): void
    {
        Queue::fake();
        AutoCloseSettings::save(['warn_enabled' => true, 'idle_minutes' => 60, 'close_after_warn_minutes' => 30]);
        $ticket = $this->ticket();
        $this->ours($ticket, 61);
        $this->artisan('app:close-inactive-tickets')->assertSuccessful();

        $this->travel(10)->minutes();
        $this->message($ticket, MessageDirection::IN, 0);
        $this->travel(30)->minutes();
        $this->artisan('app:close-inactive-tickets')->expectsOutput('Avisos: 0. Encerrados: 0.')->assertSuccessful();

        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $ticket->fresh()?->status);
        $this->assertSame(1, $this->autoReplies($ticket));
    }

    public function test_aguardando_na_fila_nunca_recebe_aviso_nem_fecha(): void
    {
        Queue::fake();
        AutoCloseSettings::save(['warn_enabled' => true, 'idle_minutes' => 60]);
        $ticket = $this->ticket(['status' => TicketStatus::AGUARDANDO, 'assigned_user_id' => null]);
        $this->ours($ticket, 10 * 24 * 60);

        $this->artisan('app:close-inactive-tickets')->expectsOutput('Avisos: 0. Encerrados: 0.')->assertSuccessful();

        $this->assertSame(TicketStatus::AGUARDANDO, $ticket->fresh()?->status);
        Queue::assertNotPushed(SendTicketMessageJob::class);
    }

    public function test_canal_parado_nao_manda_nada_e_encerra_em_x(): void
    {
        Queue::fake();
        AutoCloseSettings::save(['warn_enabled' => true, 'farewell_enabled' => true, 'idle_minutes' => 60]);
        $stopped = $this->ticket(['channel_id' => Channel::factory()->create(['waha_session' => 'parado'])->id]);
        $inactive = $this->ticket(['channel_id' => Channel::factory()->connected()->create(['waha_session' => 'inativo', 'active' => false])->id]);
        $this->ours($stopped, 61);
        $this->ours($inactive, 61);

        $this->artisan('app:close-inactive-tickets')->expectsOutput('Avisos: 0. Encerrados: 2.')->assertSuccessful();

        $this->assertSame(TicketStatus::ENCERRADO, $stopped->fresh()?->status);
        $this->assertSame(TicketStatus::ENCERRADO, $inactive->fresh()?->status);
        $this->assertSame(0, Message::query()->where('auto_reply', true)->count());
        $this->assertSame(['reason' => 'inatividade'], $stopped->events()->where('type', 'closed')->sole()->payload);
        Queue::assertNotPushed(SendTicketMessageJob::class);
    }

    public function test_limite_de_5_avisos_por_rodada(): void
    {
        Queue::fake();
        AutoCloseSettings::save(['warn_enabled' => true, 'idle_minutes' => 60]);
        foreach (range(1, 7) as $i) {
            $this->ours($this->ticket(), 61);
        }

        $this->artisan('app:close-inactive-tickets')->expectsOutput('Avisos: 5. Encerrados: 0.')->assertSuccessful();
        $this->assertSame(5, Message::query()->where('auto_reply', true)->count());
        Queue::assertPushed(SendTicketMessageJob::class, 5);

        $this->artisan('app:close-inactive-tickets')->expectsOutput('Avisos: 2. Encerrados: 0.')->assertSuccessful();
        $this->assertSame(7, Message::query()->where('auto_reply', true)->count());
        Queue::assertPushed(SendTicketMessageJob::class, 7);
    }

    public function test_limite_de_5_despedidas_por_rodada_deixa_o_resto_aberto_para_a_proxima(): void
    {
        Queue::fake();
        AutoCloseSettings::save(['farewell_enabled' => true]);
        foreach (range(1, 7) as $i) {
            $this->ours($this->ticket(), 25 * 60);
        }

        $this->artisan('app:close-inactive-tickets')->expectsOutput('Avisos: 0. Encerrados: 5.')->assertSuccessful();
        $this->assertSame(2, Ticket::query()->where('status', TicketStatus::EM_ATENDIMENTO->value)->count());
        Queue::assertPushed(SendTicketMessageJob::class, 5);

        $this->artisan('app:close-inactive-tickets')->expectsOutput('Avisos: 0. Encerrados: 2.')->assertSuccessful();
        Queue::assertPushed(SendTicketMessageJob::class, 7);
    }

    public function test_erro_num_chamado_nao_para_a_rodada_e_solta_o_travamento(): void
    {
        Exceptions::fake();
        $broken = $this->ticket();
        $healthy = $this->ticket();
        $this->ours($broken, 25 * 60);
        $this->ours($healthy, 25 * 60);
        $this->app->instance(CloseTicket::class, new class($broken->id) extends CloseTicket
        {
            public function __construct(private readonly int $failOn) {}

            public function __invoke(Ticket $ticket, ?User $user, ClosedReason $reason = ClosedReason::MANUAL, ?CloseReason $closeReason = null, ?string $summary = null): bool
            {
                if ($ticket->id === $this->failOn) {
                    throw new RuntimeException('falhou neste chamado');
                }

                return parent::__invoke($ticket, $user, $reason, $closeReason, $summary);
            }
        });

        $this->artisan('app:close-inactive-tickets')->expectsOutput('Avisos: 0. Encerrados: 1.')->assertSuccessful();

        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $broken->fresh()?->status);
        $this->assertSame(TicketStatus::ENCERRADO, $healthy->fresh()?->status);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'falhou neste chamado');
        $lock = Cache::lock('bot-ticket-'.$broken->id, 5);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_chamado_com_o_travamento_do_bot_ocupado_espera_a_proxima_rodada_e_nao_conta(): void
    {
        Queue::fake();
        AutoCloseSettings::save(['warn_enabled' => true, 'idle_minutes' => 60]);
        $busy = $this->ticket();
        $free = $this->ticket();
        $this->ours($busy, 61);
        $this->ours($free, 61);
        $held = Cache::lock('bot-ticket-'.$busy->id, 120);
        $this->assertTrue($held->get());

        $this->artisan('app:close-inactive-tickets')->expectsOutput('Avisos: 1. Encerrados: 0.')->assertSuccessful();

        $this->assertNull($busy->fresh()?->auto_close_warned_at);
        $this->assertSame(0, $this->autoReplies($busy));
        $this->assertSame(1, $this->autoReplies($free));
        $held->release();

        $this->artisan('app:close-inactive-tickets')->expectsOutput('Avisos: 1. Encerrados: 0.')->assertSuccessful();
        $this->assertSame(1, $this->autoReplies($busy));
    }

    public function test_os_ajustes_sao_lidos_uma_vez_por_rodada(): void
    {
        Queue::fake();
        AutoCloseSettings::save(['warn_enabled' => true, 'idle_minutes' => 60]);
        foreach (range(1, 5) as $i) {
            $this->ours($this->ticket(), 61 + $i);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'from "settings"')) {
                $queries[] = $query->sql;
            }
        });

        $this->artisan('app:close-inactive-tickets')->expectsOutput('Avisos: 5. Encerrados: 0.')->assertSuccessful();

        // Uma leitura por chave (8), não uma por chamado.
        $this->assertLessThanOrEqual(8, count($queries), implode('
', $queries));
    }

    public function test_chamado_cuja_ultima_e_do_cliente_nao_ocupa_vaga_dos_500_candidatos(): void
    {
        $now = now();
        $contacts = Contact::factory()->count(500)->create();
        Ticket::query()->insert($contacts->map(fn (Contact $contact): array => [
            'contact_id' => $contact->id, 'channel_id' => $this->channel->id, 'status' => TicketStatus::EM_ATENDIMENTO->value,
            'opened_at' => $now->subDays(5), 'created_at' => $now, 'updated_at' => $now,
        ])->all());
        Message::query()->insert(Ticket::query()->get(['id', 'contact_id'])->map(fn (Ticket $ticket): array => [
            'ticket_id' => $ticket->id, 'channel_id' => $this->channel->id, 'contact_id' => $ticket->contact_id, 'direction' => 'in', 'type' => 'text',
            'body' => 'oi', 'origin' => 'contato', 'sent_at' => $now->subDays(3), 'created_at' => $now, 'updated_at' => $now,
        ])->all());
        $due = $this->ticket();
        $this->ours($due, 25 * 60);

        $this->artisan('app:close-inactive-tickets')->expectsOutput('Avisos: 0. Encerrados: 1.')->assertSuccessful();

        $this->assertSame(TicketStatus::ENCERRADO, $due->fresh()?->status);
    }

    public function test_rodar_de_novo_nao_duplica_aviso_nem_encerramento(): void
    {
        Queue::fake();
        AutoCloseSettings::save(['warn_enabled' => true, 'farewell_enabled' => true, 'idle_minutes' => 60, 'close_after_warn_minutes' => 30]);
        $warned = $this->ticket();
        $closed = $this->ticket();
        $this->ours($warned, 61);
        $this->ours($closed, 61);
        $closed->forceFill(['auto_close_warned_at' => now()->subMinutes(31)])->save();
        $this->artisan('app:close-inactive-tickets')->expectsOutput('Avisos: 1. Encerrados: 1.')->assertSuccessful();

        $this->artisan('app:close-inactive-tickets')->expectsOutput('Avisos: 0. Encerrados: 0.')->assertSuccessful();

        $this->assertSame(1, $this->autoReplies($warned));
        $this->assertSame(1, $this->autoReplies($closed));
        $this->assertSame(1, $closed->events()->where('type', 'closed')->count());
        Queue::assertPushed(SendTicketMessageJob::class, 2);
    }

    public function test_texto_do_aviso_preenche_nome_e_protocolo_e_sai_sem_assinatura(): void
    {
        Queue::fake();
        AutoCloseSettings::save(['warn_enabled' => true, 'idle_minutes' => 60, 'warn_message' => 'Oi {{nome}}, o chamado {{protocolo}} segue aberto.']);
        $ticket = $this->ticket(['contact_id' => Contact::factory()->create(['name' => 'Maria Souza'])->id]);
        $this->ours($ticket, 61);

        $this->artisan('app:close-inactive-tickets')->assertSuccessful();

        $warning = $ticket->messages()->where('auto_reply', true)->sole();
        $this->assertSame('Oi Maria Souza, o chamado '.$ticket->protocol.' segue aberto.', $warning->body);
        $this->assertNull($warning->author_user_id);
        $this->assertSame(MessageOrigin::BOT, $warning->origin);
        $this->assertSame(MessageDirection::OUT, $warning->direction);
        Queue::assertPushed(SendTicketMessageJob::class, fn (SendTicketMessageJob $job): bool => $job->messageId === $warning->id);
    }

    public function test_o_horario_de_atendimento_e_lido_uma_vez_por_rodada(): void
    {
        Queue::fake();
        BusinessHour::create(['weekday' => 5, 'opens_at' => '00:00', 'closes_at' => '23:59']);
        AutoCloseSettings::save(['warn_enabled' => true, 'idle_minutes' => 60, 'business_hours_only' => true]);
        foreach (range(1, 5) as $i) {
            $this->ours($this->ticket(), 61 + $i);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'business_hours') || str_contains($query->sql, 'holidays')) {
                $queries[] = $query->sql;
            }
        });

        $this->artisan('app:close-inactive-tickets')->expectsOutput('Avisos: 5. Encerrados: 0.')->assertSuccessful();

        $this->assertLessThanOrEqual(2, count($queries), implode("\n", $queries));
    }
}
