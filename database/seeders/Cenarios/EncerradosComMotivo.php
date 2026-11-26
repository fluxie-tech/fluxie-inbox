<?php

namespace Database\Seeders\Cenarios;

use App\Actions\Inbox\CloseTicket;
use App\Enums\TicketStatus;
use App\Models\CloseReason;
use App\Models\Contact;
use App\Models\Ticket;
use Database\Seeders\SoRodaEmAmbienteDeTeste;
use Illuminate\Database\Seeder;

/**
 * Dois encerrados de um mesmo cliente, para o histórico e o cartão do contato: um por "Resolvido" com resumo e outro por
 * "Duplicado", motivo extra que fica desativado (o filtro do histórico mostra " (desativado)").
 */
class EncerradosComMotivo extends Seeder
{
    use SoRodaEmAmbienteDeTeste;

    public const NOME_DO_CONTATO = 'Cliente com Histórico';

    public function run(): void
    {
        $this->exigirAmbienteDeTeste();

        $contato = Contact::factory()->create(['name' => self::NOME_DO_CONTATO]);
        $atendente = UsuariosDeTeste::admin();
        $encerrar = app(CloseTicket::class);
        $duplicado = CloseReason::query()->firstOrCreate(['name' => 'Duplicado'], ['requires_summary' => false, 'active' => false, 'position' => 99]);

        foreach ([
            [CloseReason::query()->where('name', 'Resolvido')->firstOrFail(), 'Orçamento aprovado e pedido enviado.'],
            [$duplicado, null],
        ] as [$motivo, $resumo]) {
            $ticket = Ticket::factory()->create(['contact_id' => $contato->id, 'channel_id' => CanalConectado::canal()->id, 'status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $atendente->id]);
            $encerrar($ticket, $atendente, closeReason: $motivo, summary: $resumo);
        }
    }
}
