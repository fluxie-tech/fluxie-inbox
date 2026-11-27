<?php

namespace App\Livewire\Navigation;

use App\Support\SidebarState;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** O aviso de WhatsApp desconectado do menu, que aparece e some sem recarregar a página. */
class WhatsappWarning extends Component
{
    /** @return array<string, string> */
    public function getListeners(): array
    {
        return [
            'echo-private:inbox,.channel.status' => '$refresh',
            'realtime-reconnected' => '$refresh',
            'alerts-refresh' => '$refresh',
        ];
    }

    public function render(): View
    {
        return view('livewire.navigation.whatsapp-warning', ['down' => app(SidebarState::class)->whatsappDown()]);
    }
}
