<?php

namespace Tests\Feature\AutoClose;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Jobs\HandleWahaEvent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\WahaEvent;
use App\Services\AutoClose\LastCountingMessage;
use App\Services\Inbox\IngestMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutoCloseEchoTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    private function event(array $data): WahaEvent
    {
        return WahaEvent::create([
            'waha_event_id' => 'evt_'.uniqid(),
            'channel_id' => Channel::query()->sole()->id,
            'event' => 'message.any',
            'session' => 'main',
            'payload' => ['event' => 'message.any', 'session' => 'main', 'payload' => $data],
            'received_at' => now(),
        ]);
    }

    public function test_eco_do_aviso_adotado_mantem_auto_reply_e_nao_conta(): void
    {
        Queue::fake();
        Channel::factory()->connected()->create(['waha_session' => 'main']);
        app(IngestMessage::class)($this->event([
            'id' => 'false_'.self::PEER.'_IN', 'from' => self::PEER, 'fromMe' => false,
            'body' => 'Oi', 'timestamp' => now()->subHour()->timestamp, 'hasMedia' => false,
        ]));
        $ticket = Ticket::query()->sole();
        $client = Message::query()->sole();

        $warning = Message::factory()->create([
            'ticket_id' => $ticket->id, 'channel_id' => $ticket->channel_id, 'contact_id' => $ticket->contact_id,
            'direction' => MessageDirection::OUT, 'origin' => MessageOrigin::BOT, 'auto_reply' => true,
            'body' => 'Ainda está por aí?', 'wa_message_id' => null, 'sent_at' => now()->subMinute(),
        ]);

        (new HandleWahaEvent($this->event([
            'id' => 'true_'.self::PEER.'_W1', 'from' => '5516999990001@c.us', 'to' => self::PEER, 'fromMe' => true,
            'body' => 'Ainda está por aí?', 'timestamp' => now()->timestamp, 'hasMedia' => false, 'source' => 'api',
        ])->id))->handle();

        $this->assertSame(2, Message::query()->count());
        $warning->refresh();
        $this->assertTrue($warning->auto_reply);
        $this->assertSame('W1', $warning->wa_message_id);
        $this->assertTrue($client->is((new LastCountingMessage)->for($ticket)));
    }
}
