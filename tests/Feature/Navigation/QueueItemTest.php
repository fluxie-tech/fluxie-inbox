<?php

namespace Tests\Feature\Navigation;

use App\Enums\TicketStatus;
use App\Livewire\Navigation\QueueItem;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class QueueItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_selo_vermelho_some_no_refresh_quando_o_ticket_deixa_de_estar_estourado(): void
    {
        $ticket = Ticket::factory()->for(Channel::factory()->connected()->create())->create([
            'status' => TicketStatus::AGUARDANDO, 'waiting_since' => now()->subHour(), 'sla_due_at' => now()->subMinutes(5),
        ]);
        $this->actingAs(User::factory()->create());

        $screen = Livewire::test(QueueItem::class)->assertSeeHtml('data-test="sla-badge"')->assertSeeHtml('data-test="queue-badge"');

        Ticket::query()->whereKey($ticket->id)->update(['sla_due_at' => now()->addHour()]);

        $screen->call('$refresh')->assertDontSeeHtml('data-test="sla-badge"')->assertSeeHtml('data-test="queue-badge"');
    }

    public function test_escuta_o_que_muda_a_fila_e_os_alertas(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $listeners = Livewire::test(QueueItem::class)->instance()->getListeners();

        $this->assertSame([
            'echo-private:inbox,.ticket.updated', 'echo-private:users.'.$user->id.',.Illuminate\Notifications\Events\BroadcastNotificationCreated',
            'realtime-reconnected', 'alerts-refresh',
        ], array_keys($listeners));
    }

    public function test_na_rota_da_fila_o_item_segue_ativo_depois_do_refresh(): void
    {
        $this->actingAs(User::factory()->create());

        $html = (string) $this->get('/inbox')->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $link = (new \DOMXPath($dom))->query('//a[@data-flux-sidebar-item][1]')->item(0);
        $this->assertTrue($link instanceof \DOMElement && $link->hasAttribute('data-current'));

        Livewire::test(QueueItem::class, ['current' => true])->call('$refresh')->assertSeeHtml(' data-current="');
        // O pedido de atualização do Livewire não é a rota da Fila: quem diz se é a página atual é o layout, uma vez só.
        Livewire::test(QueueItem::class, ['current' => false])->call('$refresh')->assertDontSeeHtml(' data-current="');
    }
}
