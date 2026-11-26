<?php

namespace Tests\Feature\AutoClose;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\AutoClose\LastCountingMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LastCountingMessageTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ticket = Ticket::factory()->create(['channel_id' => Channel::factory()->create()->id]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function message(string $at, array $attributes = []): Message
    {
        return Message::factory()->create(['ticket_id' => $this->ticket->id, 'sent_at' => $at, ...$attributes]);
    }

    public function test_a_ultima_que_conta_ignora_a_resposta_automatica(): void
    {
        $client = $this->message('2026-11-26 10:00:00');
        $this->message('2026-11-26 10:00:05', ['direction' => MessageDirection::OUT, 'origin' => MessageOrigin::BOT, 'auto_reply' => true]);

        $last = (new LastCountingMessage)->for($this->ticket);

        $this->assertTrue($client->is($last));
        $this->assertFalse((new LastCountingMessage)->isOurs($client));
    }

    public function test_mensagem_da_automacao_conta_como_nossa(): void
    {
        $this->message('2026-11-26 10:00:00');
        $bot = $this->message('2026-11-26 10:01:00', ['direction' => MessageDirection::OUT, 'origin' => MessageOrigin::BOT]);

        $last = (new LastCountingMessage)->for($this->ticket);

        $this->assertTrue($bot->is($last));
        $this->assertTrue((new LastCountingMessage)->isOurs($bot));
    }

    public function test_mensagem_do_atendente_pelo_celular_conta_como_nossa(): void
    {
        $this->message('2026-11-26 10:00:00');
        $phone = $this->message('2026-11-26 10:02:00', ['direction' => MessageDirection::OUT, 'origin' => MessageOrigin::FORA_DO_PAINEL]);

        $this->assertTrue($phone->is((new LastCountingMessage)->for($this->ticket)));
        $this->assertTrue((new LastCountingMessage)->isOurs($phone));
    }

    public function test_mensagem_revogada_conta_igual(): void
    {
        $revoked = $this->message('2026-11-26 10:00:00', ['direction' => MessageDirection::OUT, 'origin' => MessageOrigin::PAINEL, 'revoked_at' => now()]);

        $this->assertTrue($revoked->is((new LastCountingMessage)->for($this->ticket)));
    }

    public function test_ticket_sem_mensagem_devolve_nulo(): void
    {
        $this->assertNull((new LastCountingMessage)->for($this->ticket));
    }
}
