<?php

namespace Tests\Feature\Inbox;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Services\Inbox\Presence;
use App\Services\Waha\WahaMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PresenceAnnounceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        Queue::fake();
        Http::fake(['*' => Http::response([], 201)]);
    }

    private function ticketOn(Channel $channel): Ticket
    {
        return Ticket::factory()->for(Contact::factory()->create(['wa_id' => '5516997524950@c.us']))->for($channel)->create();
    }

    public function test_canal_conectado_avisa_digitando_no_waha(): void
    {
        $channel = Channel::factory()->connected()->create(['waha_session' => 'main']);

        app(Presence::class)->announce($this->ticketOn($channel), true, false);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/main/presence') && ($r['presence'] ?? null) === 'typing');
    }

    public function test_canal_desconectado_nao_chama_o_waha(): void
    {
        $channel = Channel::factory()->create(['waha_session' => 'main']);

        app(Presence::class)->announce($this->ticketOn($channel), true, false);

        Http::assertNothingSent();
    }

    public function test_media_for_channel_usa_a_sessao_do_canal(): void
    {
        $channel = Channel::factory()->connected()->create(['waha_session' => 'sessao-x']);

        WahaMedia::forChannel($channel)->streamFile('abc.jpg');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/files/sessao-x/abc.jpg'));
    }
}
