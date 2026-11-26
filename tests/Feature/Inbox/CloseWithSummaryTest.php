<?php

namespace Tests\Feature\Inbox;

use App\Actions\Inbox\CloseTicket;
use App\Enums\ClosedReason;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\CloseReason;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class CloseWithSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $this->ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->connected()->create()->id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->me->id,
        ]);
    }

    private function inbox(): Testable
    {
        return Livewire::actingAs($this->me)->test(Inbox::class)->set('ticketId', $this->ticket->id);
    }

    private function reason(string $name): CloseReason
    {
        return CloseReason::query()->where('name', $name)->firstOrFail();
    }

    private function reasonWithSummary(): CloseReason
    {
        // Sem cedilha de propósito: o assertSeeInOrder compara com o JSON do Livewire, onde o ç sai como ç.
        return CloseReason::factory()->create(['name' => 'Orcamento', 'requires_summary' => true, 'active' => true, 'position' => 10]);
    }

    public function test_motivo_que_nao_pede_resumo_encerra_sem_resumo(): void
    {
        $resolvido = $this->reason('Resolvido');

        $this->inbox()->call('openPanel', 'close')->set('closeReasonId', (string) $resolvido->id)->call('close')->assertHasNoErrors();

        $closed = $this->ticket->fresh();
        $this->assertSame(TicketStatus::ENCERRADO, $closed?->status);
        $this->assertSame($resolvido->id, $closed?->close_reason_id);
        $this->assertNull($closed?->close_summary);
    }

    public function test_o_painel_nao_encerra_sem_motivo(): void
    {
        $this->inbox()->call('openPanel', 'close')->assertSee('Motivo do encerramento')
            ->call('close')->assertHasErrors(['closeReasonId'])
            ->set('closeReasonId', '999999')->call('close')->assertHasErrors(['closeReasonId']);

        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $this->ticket->fresh()?->status);
    }

    public function test_motivo_que_pede_resumo_recusa_resumo_em_branco(): void
    {
        $motivo = $this->reasonWithSummary();

        $this->inbox()->call('openPanel', 'close')->set('closeReasonId', (string) $motivo->id)->set('closeSummary', '   ')->call('close')
            ->assertHasErrors(['closeSummary' => 'required']);

        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $this->ticket->fresh()?->status);
    }

    public function test_resumo_preenchido_curto_demais_e_recusado_mesmo_quando_opcional(): void
    {
        $this->inbox()->call('openPanel', 'close')->set('closeReasonId', (string) $this->reason('Resolvido')->id)->set('closeSummary', 'ok')->call('close')
            ->assertHasErrors(['closeSummary' => 'min']);
    }

    public function test_resumo_acima_de_500_caracteres_e_recusado(): void
    {
        $this->inbox()->call('openPanel', 'close')->set('closeReasonId', (string) $this->reason('Resolvido')->id)->set('closeSummary', str_repeat('a', 501))->call('close')->assertHasErrors(['closeSummary']);
    }

    public function test_motivo_desativado_com_o_painel_aberto_e_recusado(): void
    {
        $resolvido = $this->reason('Resolvido');
        $component = $this->inbox()->call('openPanel', 'close')->set('closeReasonId', (string) $resolvido->id);

        $resolvido->update(['active' => false]);

        $component->call('close')->assertHasErrors(['closeReasonId']);
        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $this->ticket->fresh()?->status);
    }

    public function test_encerra_gravando_motivo_resumo_e_o_evento(): void
    {
        $motivo = $this->reasonWithSummary();

        $this->inbox()->call('openPanel', 'close')->set('closeReasonId', (string) $motivo->id)->set('closeSummary', '  Cliente vai aprovar com o socio.  ')->call('close')->assertHasNoErrors();

        $closed = $this->ticket->fresh();
        $this->assertSame($motivo->id, $closed?->close_reason_id);
        $this->assertSame('Cliente vai aprovar com o socio.', $closed?->close_summary);
        $this->assertSame(ClosedReason::MANUAL, $closed?->closed_reason);
        $this->assertSame(
            ['reason' => 'manual', 'close_reason_id' => $motivo->id, 'summary' => 'Cliente vai aprovar com o socio.'],
            $this->ticket->events()->where('type', 'closed')->sole()->payload,
        );
    }

    public function test_evento_de_encerramento_guarda_o_id_do_motivo(): void
    {
        $motivo = $this->reason('Resolvido');

        (new CloseTicket)($this->ticket, $this->me, ClosedReason::MANUAL, $motivo, 'x');

        $payload = $this->ticket->events()->where('type', 'closed')->sole()->payload;
        $this->assertSame($motivo->id, $payload['close_reason_id']);
        $this->assertArrayNotHasKey('outcome', $payload);
    }

    public function test_encerrar_duas_vezes_grava_um_evento_so_e_mantem_o_primeiro_resumo(): void
    {
        (new CloseTicket)($this->ticket, $this->me, ClosedReason::MANUAL, $this->reason('Resolvido'), 'Primeiro resumo');
        (new CloseTicket)($this->ticket->fresh() ?? $this->ticket, $this->me, ClosedReason::MANUAL, $this->reason('Sem resposta'), 'Segundo resumo');

        $this->assertSame(1, $this->ticket->events()->where('type', 'closed')->count());
        $this->assertSame('Primeiro resumo', $this->ticket->fresh()?->close_summary);
        $this->assertSame($this->reason('Resolvido')->id, $this->ticket->fresh()?->close_reason_id);
    }

    public function test_resumo_so_com_espacos_nao_conta_como_texto(): void
    {
        $this->inbox()->call('openPanel', 'close')->set('closeReasonId', (string) $this->reason('Resolvido')->id)->set('closeSummary', '  ab')->call('close')->assertHasErrors(['closeSummary']);
    }

    public function test_quem_encerra_depois_de_outro_recebe_o_aviso_em_vez_de_erro(): void
    {
        $component = $this->inbox()->call('openPanel', 'close')->set('closeReasonId', (string) $this->reason('Resolvido')->id)->set('closeSummary', 'Cotacao enviada');

        (new CloseTicket)($this->ticket, User::factory()->create(['role' => UserRole::SUPERVISOR]), ClosedReason::MANUAL, $this->reason('Sem resposta'), 'Primeiro resumo');

        $component->call('close')->assertSet('notice', 'Este ticket já foi encerrado por outra pessoa.')->assertSet('panel', null);
        $this->assertSame('Primeiro resumo', $this->ticket->fresh()?->close_summary);
    }

    public function test_o_painel_lista_so_os_ativos_na_ordem_e_avisa_quando_o_resumo_e_obrigatorio(): void
    {
        $motivo = $this->reasonWithSummary();
        CloseReason::factory()->create(['name' => 'Motivo apagado', 'active' => false, 'position' => 11]);

        $this->inbox()->call('openPanel', 'close')
            ->assertSeeInOrder(['Resolvido', 'Sem resposta', 'Cliente pediu', 'Orcamento'])
            ->assertDontSee('Motivo apagado')
            ->assertSee('Opcional. Aparece no histórico')
            ->set('closeReasonId', (string) $motivo->id)
            ->assertSee('Obrigatório para este motivo.');
    }

    public function test_quem_nao_ve_o_ticket_nao_abre_o_painel_de_encerramento(): void
    {
        $other = User::factory()->create(['role' => UserRole::ATENDENTE]);

        Livewire::actingAs($other)->test(Inbox::class)->set('ticketId', $this->ticket->id)->call('openPanel', 'close')->assertNotFound();
    }

    public function test_encerramento_sem_motivo_continua_valendo_para_a_rotina(): void
    {
        (new CloseTicket)($this->ticket, $this->me, ClosedReason::INATIVIDADE);

        $closed = $this->ticket->fresh();
        $this->assertNull($closed?->close_reason_id);
        $this->assertNull($closed?->close_summary);
        $this->assertSame(['reason' => 'inatividade'], $this->ticket->events()->where('type', 'closed')->sole()->payload);
    }
}
