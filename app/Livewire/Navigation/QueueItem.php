<?php

namespace App\Livewire\Navigation;

use App\Support\Navigation;
use App\Support\NavItem;
use App\Support\SidebarState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/** O item "Fila" do menu com os selos de aguardando e de SLA estourado, que se atualizam sem recarregar a página. */
class QueueItem extends Component
{
    /** Se a página aberta é a Fila: o layout diz ao montar (no pedido de atualização do Livewire a rota não é a da página). */
    public bool $current = false;

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return [
            'echo-private:inbox,.ticket.updated' => '$refresh',
            'echo-private:users.'.Auth::id().',.Illuminate\Notifications\Events\BroadcastNotificationCreated' => '$refresh',
            'realtime-reconnected' => '$refresh',
            'alerts-refresh' => '$refresh',
        ];
    }

    public function render(): View
    {
        $user = Auth::user();
        $item = $user === null ? null : collect(app(Navigation::class)->primary($user))->first(fn (NavItem $nav): bool => $nav->route === 'inbox');

        return view('livewire.navigation.queue-item', [
            'item' => $item,
            'counts' => $user === null ? ['waiting' => 0, 'breached' => 0] : app(SidebarState::class)->counts($user),
        ]);
    }
}
