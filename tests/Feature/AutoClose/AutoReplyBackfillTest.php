<?php

namespace Tests\Feature\AutoClose;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutoReplyBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_26_000003_add_auto_reply_to_messages_and_warned_at_to_tickets.php';

    /** No deploy, a resposta fora do horário que já existe não pode contar como mensagem nossa. */
    public function test_a_migracao_marca_so_a_resposta_fora_do_horario(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]);
        $ticket = Ticket::factory()->create(['channel_id' => Channel::factory()->create()->id]);
        $other = Ticket::factory()->create(['channel_id' => $ticket->channel_id]);
        $out = ['direction' => MessageDirection::OUT, 'origin' => MessageOrigin::BOT];
        $afterHours = Message::factory()->create(['ticket_id' => $ticket->id, ...$out]);
        $later = Message::factory()->create(['ticket_id' => $ticket->id, ...$out]);
        $noEvent = Message::factory()->create(['ticket_id' => $other->id, ...$out]);
        TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'after_hours_reply']);
        // O evento nasce antes da mensagem na mesma transação: a anterior ao evento não é a resposta.
        DB::table('messages')->where('id', $afterHours->id)->update(['created_at' => now()->addSecond()]);
        DB::table('messages')->where('id', $later->id)->update(['created_at' => now()->addSeconds(5)]);

        Artisan::call('migrate', ['--path' => self::MIGRATION]);

        $this->assertTrue((bool) DB::table('messages')->where('id', $afterHours->id)->value('auto_reply'));
        $this->assertFalse((bool) DB::table('messages')->where('id', $later->id)->value('auto_reply'));
        $this->assertFalse((bool) DB::table('messages')->where('id', $noEvent->id)->value('auto_reply'));
    }
}
