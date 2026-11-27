<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Transcript;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** O botão direito na conversa (resources/js/conversation-context-menu.js): o que a view precisa oferecer ao componente Alpine. */
class TranscriptContextMenuTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Ticket, 2: Message} */
    private function conversa(): array
    {
        $me = User::factory()->create(['role' => UserRole::ADMIN]);
        $ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->create()->id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $me->id,
        ]);
        $message = Message::factory()->for($ticket)->create(['body' => 'Bom dia', 'wa_message_id' => 'W1']);

        return [$me, $ticket, $message];
    }

    public function test_a_raiz_da_fila_tem_o_componente_e_o_menu_copiar(): void
    {
        [$me, $ticket] = $this->conversa();

        $html = Livewire::actingAs($me)->test(Transcript::class, ['ticketId' => $ticket->id])->html();

        $this->assertSame(1, substr_count($html, 'x-data="conversationContextMenu"'));
        $this->assertMatchesRegularExpression('/<div[^>]*role="menu"[^>]*data-selection-menu|<div[^>]*data-selection-menu[^>]*role="menu"/', $html);
        $this->assertStringContainsString('Copiar', $html);
    }

    public function test_bolha_com_menu_leva_o_id_e_aceita_foco(): void
    {
        [$me, $ticket, $message] = $this->conversa();

        $html = Livewire::actingAs($me)->test(Transcript::class, ['ticketId' => $ticket->id])->html();

        $this->assertMatchesRegularExpression('/<div[^>]*data-message-menu="'.$message->id.'"[^>]*tabindex="-1"|<div[^>]*tabindex="-1"[^>]*data-message-menu="'.$message->id.'"/', $html);
    }

    public function test_historico_tem_o_copiar_e_nenhuma_bolha_com_menu(): void
    {
        [$me, $ticket] = $this->conversa();
        $this->actingAs($me);

        $html = view('livewire.inbox.transcript', ['messages' => $ticket->messages()->get(), 'readonly' => true])->render();

        $this->assertSame(1, substr_count($html, 'x-data="conversationContextMenu"'));
        $this->assertStringContainsString('data-selection-menu', $html);
        $this->assertStringNotContainsString('data-message-menu=', $html);
    }

    public function test_bolha_apagada_nao_tem_menu_nem_marcador(): void
    {
        [$me, $ticket, $message] = $this->conversa();
        $message->update(['revoked_at' => now()]);

        $html = Livewire::actingAs($me)->test(Transcript::class, ['ticketId' => $ticket->id])->html();

        $this->assertStringNotContainsString('data-message-menu=', $html);
    }

    public function test_os_dois_menus_fecham_quando_outro_abre_e_no_esc(): void
    {
        [$me, $ticket] = $this->conversa();

        $html = Livewire::actingAs($me)->test(Transcript::class, ['ticketId' => $ticket->id])->html();

        $this->assertMatchesRegularExpression('/<div[^>]*x-data="messageMenu"[^>]*x-on:conversation-menu-open\.window="closeUnless\(\$event\)"/', $html);
        $this->assertMatchesRegularExpression('/<div[^>]*x-data="messageMenu"[^>]*x-on:keydown\.escape\.window="escape\(\)"/', $html);
        $this->assertMatchesRegularExpression('/<div[^>]*x-data="conversationContextMenu"[^>]*x-on:conversation-menu-open\.window="closeUnless\(\$event\)"/', $html);
    }
}
