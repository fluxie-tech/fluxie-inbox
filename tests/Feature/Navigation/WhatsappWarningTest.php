<?php

namespace Tests\Feature\Navigation;

use App\Enums\ChannelStatus;
use App\Livewire\Navigation\WhatsappWarning;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WhatsappWarningTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_aviso_some_no_refresh_quando_o_canal_volta(): void
    {
        $channel = Channel::factory()->create(['status' => ChannelStatus::STOPPED]);
        $this->actingAs(User::factory()->create());

        $screen = Livewire::test(WhatsappWarning::class)->assertSeeHtml('data-test="whatsapp-down"')->assertSee('WhatsApp desconectado');

        $channel->update(['status' => ChannelStatus::WORKING]);

        $screen->call('$refresh')->assertDontSeeHtml('data-test="whatsapp-down"')->assertDontSee('WhatsApp desconectado');
    }

    public function test_escuta_o_status_do_canal_e_a_reconexao(): void
    {
        $this->actingAs(User::factory()->create());

        $listeners = Livewire::test(WhatsappWarning::class)->instance()->getListeners();

        $this->assertSame(['echo-private:inbox,.channel.status', 'realtime-reconnected', 'alerts-refresh'], array_keys($listeners));
    }
}
