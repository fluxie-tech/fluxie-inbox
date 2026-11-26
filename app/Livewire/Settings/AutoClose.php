<?php

namespace App\Livewire\Settings;

use App\Models\Contact;
use App\Models\Ticket;
use App\Settings\AutoCloseSettings;
use Closure;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** O painel do encerramento automático: liga, tempos (em horas e minutos), aviso, despedida e horário de atendimento. */
class AutoClose extends Component
{
    public bool $enabled = true;

    public int $idleHours = 24;

    public int $idleMinutes = 0;

    public bool $warnEnabled = false;

    public string $warnMessage = '';

    public int $afterWarnHours = 1;

    public int $afterWarnMinutes = 0;

    public bool $farewellEnabled = false;

    public string $farewellMessage = '';

    public bool $businessHoursOnly = false;

    public function mount(): void
    {
        $this->authorize('admin');

        $this->enabled = AutoCloseSettings::enabled();
        [$this->idleHours, $this->idleMinutes] = $this->split(AutoCloseSettings::idleMinutes());
        $this->warnEnabled = AutoCloseSettings::warnEnabled();
        $this->warnMessage = AutoCloseSettings::warnMessage();
        [$this->afterWarnHours, $this->afterWarnMinutes] = $this->split(AutoCloseSettings::closeAfterWarnMinutes());
        $this->farewellEnabled = AutoCloseSettings::farewellEnabled();
        $this->farewellMessage = AutoCloseSettings::farewellMessage();
        $this->businessHoursOnly = AutoCloseSettings::businessHoursOnly();
    }

    public function save(): void
    {
        $this->authorize('admin');

        $max = 'max:'.AutoCloseSettings::MAX_MESSAGE;
        $this->validate([
            'idleHours' => ['integer', 'min:0'],
            'idleMinutes' => ['integer', 'min:0', $this->range($this->idleHours * 60 + $this->idleMinutes, AutoCloseSettings::MAX_IDLE_MINUTES, 'O tempo vai de 15 minutos a 30 dias.')],
            'afterWarnHours' => ['integer', 'min:0'],
            'afterWarnMinutes' => ['integer', 'min:0', $this->range($this->afterWarnHours * 60 + $this->afterWarnMinutes, AutoCloseSettings::MAX_AFTER_WARN_MINUTES, 'A espera vai de 15 minutos a 7 dias.')],
            'warnMessage' => ['required', 'string', $max],
            'farewellMessage' => ['required', 'string', $max],
        ], [
            'required' => 'Escreva o texto.',
            'warnMessage.max' => 'O texto passa de 500 caracteres.',
            'farewellMessage.max' => 'O texto passa de 500 caracteres.',
            'integer' => 'Use números inteiros.',
            'min' => 'Use números inteiros.',
        ]);

        AutoCloseSettings::save([
            'enabled' => $this->enabled,
            'idle_minutes' => $this->idleHours * 60 + $this->idleMinutes,
            'warn_enabled' => $this->warnEnabled,
            'warn_message' => $this->warnMessage,
            'close_after_warn_minutes' => $this->afterWarnHours * 60 + $this->afterWarnMinutes,
            'farewell_enabled' => $this->farewellEnabled,
            'farewell_message' => $this->farewellMessage,
            'business_hours_only' => $this->businessHoursOnly,
        ]);

        session()->flash('saved-auto-close', 'Encerramento automático salvo.');
    }

    public function render(): View
    {
        $ticket = new Ticket(['protocol' => '2026-000123']);
        $ticket->setRelation('contact', new Contact(['name' => 'Maria']));

        return view('livewire.settings.auto-close', [
            'warnPreview' => AutoCloseSettings::render($this->warnMessage, $ticket),
            'farewellPreview' => AutoCloseSettings::render($this->farewellMessage, $ticket),
        ]);
    }

    /**
     * Regra em closure: o total em minutos (horas e minutos) precisa caber na faixa. A assinatura traz o atributo.
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    private function range(int $total, int $max, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($total, $max, $message): void {
            if ($total < AutoCloseSettings::MIN_MINUTES || $total > $max) {
                $fail($message);
            }
        };
    }

    /** @return array{int, int} */
    private function split(int $minutes): array
    {
        return [intdiv($minutes, 60), $minutes % 60];
    }
}
