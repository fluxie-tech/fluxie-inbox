<?php

namespace App\Livewire\Settings;

use App\Models\Contact;
use App\Models\Ticket;
use App\Settings\AutoCloseSettings;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/** O painel do encerramento automático: liga, tempos (em horas e minutos), aviso, despedida e horário de atendimento. */
class AutoClose extends Component
{
    public bool $enabled = true;

    public int|string|null $idleHours = 24;

    public int|string|null $idleMinutes = 0;

    public bool $warnEnabled = false;

    public string $warnMessage = '';

    public int|string|null $afterWarnHours = 1;

    public int|string|null $afterWarnMinutes = 0;

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
            'idleMinutes' => ['required', $this->range($this->idleHours, $this->idleMinutes, AutoCloseSettings::MAX_IDLE_MINUTES, 'O tempo vai de 15 minutos a 30 dias.')],
            'afterWarnMinutes' => ['required', $this->range($this->afterWarnHours, $this->afterWarnMinutes, AutoCloseSettings::MAX_AFTER_WARN_MINUTES, 'A espera vai de 15 minutos a 7 dias.')],
            'warnMessage' => [Rule::requiredIf($this->warnEnabled), 'nullable', 'string', $max],
            'farewellMessage' => [Rule::requiredIf($this->farewellEnabled), 'nullable', 'string', $max],
        ], [
            'idleMinutes.required' => 'O tempo vai de 15 minutos a 30 dias.',
            'afterWarnMinutes.required' => 'A espera vai de 15 minutos a 7 dias.',
            'required' => 'Escreva o texto.',
            'warnMessage.max' => 'O texto passa de 500 caracteres.',
            'farewellMessage.max' => 'O texto passa de 500 caracteres.',
        ]);

        AutoCloseSettings::save([
            'enabled' => $this->enabled,
            'idle_minutes' => self::total($this->idleHours, $this->idleMinutes),
            'warn_enabled' => $this->warnEnabled,
            'warn_message' => trim($this->warnMessage) === '' ? AutoCloseSettings::DEFAULT_WARN : $this->warnMessage,
            'close_after_warn_minutes' => self::total($this->afterWarnHours, $this->afterWarnMinutes),
            'farewell_enabled' => $this->farewellEnabled,
            'farewell_message' => trim($this->farewellMessage) === '' ? AutoCloseSettings::DEFAULT_FAREWELL : $this->farewellMessage,
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
            'farewellPreview' => AutoCloseSettings::render($this->farewellMessage, $ticket, AutoCloseSettings::DEFAULT_FAREWELL),
        ]);
    }

    /**
     * Regra em closure: horas e minutos são inteiros não negativos e o total cabe na faixa. A assinatura traz o atributo.
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    private function range(int|string|null $hours, int|string|null $minutes, int $max, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($hours, $minutes, $max, $message): void {
            $valid = filter_var($hours, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false
                && filter_var($minutes, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false;
            $total = self::total($hours, $minutes);

            if (! $valid || $total < AutoCloseSettings::MIN_MINUTES || $total > $max) {
                $fail($message);
            }
        };
    }

    private static function total(int|string|null $hours, int|string|null $minutes): int
    {
        return (int) $hours * 60 + (int) $minutes;
    }

    /** @return array{int, int} */
    private function split(int $minutes): array
    {
        return [intdiv($minutes, 60), $minutes % 60];
    }
}
