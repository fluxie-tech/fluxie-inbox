<?php

namespace App\Livewire\Settings;

use App\Enums\HolidayKind;
use App\Models\Holiday;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Os feriados do horário de atendimento: lista com tipo, cadastro à mão e a chave "a empresa fecha". */
class Holidays extends Component
{
    public string $holidayDate = '';

    public string $holidayName = '';

    public function mount(): void
    {
        $this->authorize('admin');
    }

    public function addHoliday(): void
    {
        $this->authorize('admin');

        $this->validate([
            'holidayDate' => ['required', 'date_format:Y-m-d', 'unique:holidays,date'],
            'holidayName' => ['required', 'string', 'max:120'],
        ], [
            'holidayDate.required' => 'Informe a data do feriado.',
            'holidayDate.date_format' => 'Data inválida.',
            'holidayDate.unique' => 'Essa data já está cadastrada.',
            'holidayName.required' => 'Informe o nome do feriado.',
            'holidayName.max' => 'O nome passa de 120 caracteres.',
        ]);

        Holiday::create(['date' => $this->holidayDate, 'name' => trim($this->holidayName), 'kind' => HolidayKind::MANUAL, 'closes' => true]);

        $this->reset('holidayDate', 'holidayName');
    }

    public function removeHoliday(int $id): void
    {
        $this->authorize('admin');

        Holiday::query()->whereKey($id)->delete();
    }

    public function setCloses(int $id, bool $closes): void
    {
        $this->authorize('admin');

        Holiday::query()->whereKey($id)->update(['closes' => $closes]);
    }

    public function render(): View
    {
        return view('livewire.settings.holidays', ['holidays' => Holiday::query()->orderBy('date')->get()]);
    }
}
