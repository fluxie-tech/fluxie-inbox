<?php

namespace Tests\Feature\Inbox;

use App\Enums\MessageOrigin;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\WahaEvent;
use App\Services\Inbox\IngestMessage;
use App\Settings\CallSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CallReplyEchoTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
    }

    private function echo(string $body, string $source = 'api', ?string $mode = null): ?Message
    {
        $payload = ['event' => 'message.any', 'session' => 'main', 'payload' => [
            'id' => 'true_5516997524950@c.us_'.uniqid(),
            'from' => '5516999990001@c.us',
            'to' => self::PEER,
            'fromMe' => true,
            'source' => $source,
            'body' => $body,
            'timestamp' => 1_793_000_100,
            'hasMedia' => false,
        ]];

        if ($mode !== null) {
            $payload['mode'] = $mode;
        }

        $event = WahaEvent::create([
            'waha_event_id' => 'evt_'.uniqid(),
            'channel_id' => $this->channel->id,
            'event' => 'message.any',
            'session' => 'main',
            'payload' => $payload,
            'received_at' => now(),
        ]);

        return app(IngestMessage::class)($event);
    }

    private function openTicket(): Ticket
    {
        $inbound = WahaEvent::create([
            'waha_event_id' => 'evt_'.uniqid(),
            'channel_id' => $this->channel->id,
            'event' => 'message',
            'session' => 'main',
            'payload' => ['event' => 'message', 'session' => 'main', 'payload' => [
                'id' => 'false_5516997524950@c.us_IN', 'from' => self::PEER, 'fromMe' => false,
                'body' => 'Oi', 'timestamp' => 1_793_000_000, 'hasMedia' => false,
            ]],
            'received_at' => now(),
        ]);
        app(IngestMessage::class)($inbound);

        return Ticket::query()->sole();
    }

    public function test_eco_da_mensagem_de_ligacao_sem_ticket_nao_abre_ticket(): void
    {
        $this->assertNull($this->echo(CallSettings::message()));
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(0, Message::query()->count());
    }

    public function test_eco_da_mensagem_de_ligacao_nao_para_o_sla_do_ticket_aberto(): void
    {
        $ticket = $this->openTicket();

        $this->assertNull($this->echo(CallSettings::message()));

        $this->assertSame(1, Message::query()->count());
        $this->assertNull($ticket->fresh()?->last_outbound_at);
    }

    public function test_texto_diferente_do_configurado_entra_como_hoje(): void
    {
        $message = $this->echo('Outra coisa');

        $this->assertSame(MessageOrigin::FORA_DO_PAINEL, $message?->origin);
    }

    public function test_com_a_chave_desligada_o_eco_entra_como_hoje(): void
    {
        CallSettings::save(false, CallSettings::message());

        $this->assertNotNull($this->echo(CallSettings::message()));
    }

    public function test_texto_antigo_relido_pela_recuperacao_depois_da_troca_e_ignorado(): void
    {
        CallSettings::save(true, 'Texto A');
        CallSettings::save(true, 'Texto B');

        $this->assertNull($this->echo('Texto A', mode: 'recovered'));
        $this->assertSame(0, Message::query()->count());
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_mesmo_texto_digitado_no_celular_entra_como_hoje(): void
    {
        $this->assertNotNull($this->echo(CallSettings::message(), source: 'app'));
    }
}
