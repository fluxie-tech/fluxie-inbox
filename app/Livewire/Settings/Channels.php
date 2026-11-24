<?php

namespace App\Livewire\Settings;

use App\Actions\Channels\CreateChannel;
use App\Enums\AuditAction;
use App\Models\Channel;
use App\Models\WahaEvent;
use App\Rules\NoControlChars;
use App\Services\Audit\Auditor;
use App\Services\Waha\WahaCalls;
use App\Settings\CallSettings;
use App\Settings\WahaSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Canais')]
class Channels extends Component
{
    public string $sessionName = '';

    public bool $rejectCalls = true;

    public string $callMessage = '';

    public string $callsNotice = '';

    public function mount(): void
    {
        $this->rejectCalls = CallSettings::rejects();
        $this->callMessage = CallSettings::message();
    }

    public function saveCalls(): void
    {
        $this->authorize('admin');

        $this->callsNotice = '';
        $this->callMessage = trim($this->callMessage);
        $this->validate([
            'callMessage' => ['required_if:rejectCalls,true', 'string', 'max:'.CallSettings::MAX_MESSAGE, new NoControlChars(allowNewlines: true)],
        ], attributes: ['callMessage' => 'mensagem']);

        CallSettings::save($this->rejectCalls, $this->callMessage);
        Auditor::record(AuditAction::CallSettingsUpdated, auth()->user(), detail: ['reject' => $this->rejectCalls]);

        $this->callMessage = CallSettings::message();

        $result = app(WahaCalls::class)->applyAll();
        $this->callsNotice = match (true) {
            $result['applied'] + $result['unchanged'] + $result['failed'] === 0 => 'Configuração salva. Nenhum canal conectado agora; ela vale quando um canal conectar.',
            $result['failed'] === 0 => 'Configuração aplicada aos canais.',
            default => 'Configuração salva; será aplicada aos canais quando o WhatsApp responder.',
        };
    }

    public function addChannel(): void
    {
        app(CreateChannel::class)($this->sessionName, auth()->user());

        $this->reset('sessionName');
    }

    /** Um cartão apagou o canal: só pede nova leitura da lista. */
    #[On('channel-deleted')]
    public function refreshList(): void {}

    public function render(): View
    {
        return view('livewire.settings.channels', [
            'channels' => Channel::query()->orderBy('id')->get(),
            'webhookUrl' => WahaSettings::webhookUrl(),
            // "Recebendo" = chegou algum evento do WAHA nos últimos 10 minutos.
            'receiving' => WahaEvent::query()->where('received_at', '>=', now()->subMinutes(10))->exists(),
            'lastEvents' => WahaEvent::query()
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (WahaEvent $event): array => [
                    'event' => $event->event,
                    'session' => $event->session,
                    'at' => $event->received_at->timezone(config()->string('app.display_timezone'))->format('d/m/Y H:i:s'),
                ])
                ->all(),
        ]);
    }
}
