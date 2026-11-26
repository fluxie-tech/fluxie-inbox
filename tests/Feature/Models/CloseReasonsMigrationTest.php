<?php

namespace Tests\Feature\Models;

use App\Enums\CloseOutcome;
use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\CloseReason;
use App\Models\Ticket;
use App\Models\TicketEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CloseReasonsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_26_000001_create_close_reasons.php';

    public function test_a_migracao_cria_os_tres_motivos_na_ordem(): void
    {
        $this->assertSame(['Resolvido', 'Sem resposta', 'Cliente pediu'], CloseReason::query()->ordered()->pluck('name')->all());
        $this->assertSame([1, 2, 3], CloseReason::query()->ordered()->pluck('position')->all());
        $this->assertSame(0, CloseReason::query()->where('requires_summary', true)->count());
        $this->assertSame(3, CloseReason::query()->active()->count());
    }

    public function test_a_migracao_liga_os_tickets_e_os_eventos_antigos_ao_motivo(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]);
        $channel = Channel::factory()->create();
        $comMotivo = Ticket::factory()->create([
            'channel_id' => $channel->id,
            'status' => TicketStatus::ENCERRADO,
            'closed_at' => now(),
            'close_outcome' => CloseOutcome::SEM_RESPOSTA,
        ]);
        $semMotivo = Ticket::factory()->create(['channel_id' => $channel->id, 'status' => TicketStatus::ENCERRADO, 'closed_at' => now()]);
        $evento = TicketEvent::query()->create([
            'ticket_id' => $comMotivo->id,
            'type' => 'closed',
            'payload' => ['reason' => 'manual', 'outcome' => 'sem_resposta'],
        ]);

        Artisan::call('migrate', ['--path' => self::MIGRATION]);

        $id = CloseReason::query()->where('name', 'Sem resposta')->value('id');
        $this->assertSame($id, $comMotivo->fresh()?->close_reason_id);
        $this->assertNull($semMotivo->fresh()?->close_reason_id);
        $this->assertSame($id, $evento->fresh()?->payload['close_reason_id']);
        $this->assertSame('sem_resposta', $evento->fresh()?->payload['outcome']);
    }

    public function test_nome_repetido_sem_diferenciar_maiusculas_e_barrado_pelo_banco(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);
        CloseReason::factory()->create(['name' => 'resolvido']);
    }
}
