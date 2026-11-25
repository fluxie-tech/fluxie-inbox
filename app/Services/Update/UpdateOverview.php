<?php

namespace App\Services\Update;

use App\Enums\UpdateStatus;
use App\Models\SystemUpdate;
use App\Settings\UpdateSettings;

class UpdateOverview
{
    /** Minutos abertos sem fechar: depois disso a tela deixa de prometer e manda para a recuperação. */
    private const STALE_MINUTES = 30;

    public function __construct(private readonly InstalledVersion $installed, private readonly UpdateGate $gate) {}

    /**
     * Tudo o que a tela do sistema mostra sobre a atualização.
     *
     * @return array<string, mixed>
     */
    public function forView(string $timezone): array
    {
        $current = $this->installed->current();
        $open = SystemUpdate::open();
        $last = $open ?? SystemUpdate::query()->latest('id')->first();
        $lastCheck = UpdateSettings::lastCheck();
        $saved = UpdateSettings::tokenSavedAt();
        $steps = [UpdateStatus::Conferindo, UpdateStatus::Backup, UpdateStatus::Aplicando, UpdateStatus::Reiniciando];

        return [
            'installed' => $this->installed->release() === null ? null : $current,
            'sha' => substr((string) preg_replace('/^sha-/', '', $current), 0, 7),
            'hasService' => $this->gate->hasService(),
            'canUpdate' => $this->gate->canUpdate(),
            'target' => $this->gate->target(),
            'latest' => UpdateSettings::latest(),
            'checkEnabled' => UpdateSettings::checkEnabled(),
            'lastCheck' => $lastCheck === null ? null : ['at' => $lastCheck['at']->timezone($timezone)->format('d/m/Y H:i'), 'ok' => $lastCheck['ok'], 'error' => $lastCheck['error']],
            'tokenSaved' => UpdateSettings::token() !== null,
            'tokenSavedAt' => $saved?->timezone($timezone)->format('d/m'),
            'confirmWord' => UpdateGate::CONFIRM_WORD,
            'last' => $last,
            'open' => $open !== null,
            'stale' => $open !== null && $open->started_at !== null && $open->started_at->lt(now()->subMinutes(self::STALE_MINUTES)),
            'steps' => array_map(fn (UpdateStatus $step): array => [
                'label' => $step->label(),
                'state' => $last === null ? 'pending' : $this->stepState($step, $steps, $last->status),
            ], $steps),
        ];
    }

    /**
     * @param  list<UpdateStatus>  $steps
     */
    private function stepState(UpdateStatus $step, array $steps, UpdateStatus $status): string
    {
        $at = array_search($status, $steps, true);

        return match (true) {
            $at === false => 'pending', // fechada: a tela não mostra os passos, só o resultado escrito
            array_search($step, $steps, true) < $at => 'done',
            $step === $status => 'running',
            default => 'pending',
        };
    }
}
