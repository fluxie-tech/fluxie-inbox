<?php

namespace App\Livewire\Settings;

use App\Actions\Holidays\ImportHolidays;
use App\Enums\HolidayKind;
use App\Models\Holiday;
use App\Services\Holidays\HolidayImportParser;
use App\Services\Holidays\HolidayImportRow;
use App\Services\Holidays\HolidayPrompt;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/** Os feriados do horário de atendimento: lista com tipo, cadastro à mão, a chave "a empresa fecha" e a importação por JSON. */
class Holidays extends Component
{
    use WithFileUploads;

    private const WEEKDAYS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];

    public string $holidayDate = '';

    public string $holidayName = '';

    public string $promptCity = '';

    public string $promptUf = '';

    public string $promptFrom = '';

    public string $promptTo = '';

    /** @var TemporaryUploadedFile|null */
    public $importFile;

    public ?string $importError = null;

    public ?int $imported = null;

    /** @var list<array{date: string, weekday: string, name: string, kind: string, kindLabel: string, state: string, status: string}> */
    #[Locked]
    public array $preview = [];

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

    public function updatedPromptUf(): void
    {
        $this->promptUf = mb_strtoupper(trim($this->promptUf));
    }

    public function updatedImportFile(HolidayImportParser $parser): void
    {
        $this->authorize('admin');

        $this->reset('preview', 'importError', 'imported');

        if ($this->importFile === null) {
            return;
        }

        $result = $this->readFile($this->importFile, $parser);
        $this->importError = $result['error'];
        $this->preview = array_map($this->previewLine(...), $result['rows']);
    }

    public function import(HolidayImportParser $parser, ImportHolidays $import): void
    {
        $this->authorize('admin');

        if ($this->importFile === null) {
            return;
        }

        $this->imported = $import($this->readFile($this->importFile, $parser)['rows']);
        $this->reset('importFile', 'preview', 'importError');
    }

    public function cancelImport(): void
    {
        $this->authorize('admin');

        $this->reset('importFile', 'preview', 'importError', 'imported');
    }

    /** @return array{error: ?string, rows: list<HolidayImportRow>} */
    private function readFile(TemporaryUploadedFile $file, HolidayImportParser $parser): array
    {
        $this->validate(['importFile' => ['file', 'max:100', 'extensions:json']], [
            'importFile.file' => 'Envie um arquivo .json de até 100 KB.',
            'importFile.max' => 'Envie um arquivo .json de até 100 KB.',
            'importFile.extensions' => 'Envie um arquivo .json de até 100 KB.',
        ]);

        $existing = array_values(Holiday::query()->get()->map(fn (Holiday $holiday) => $holiday->date->format('Y-m-d'))->all());

        return $parser->parse((string) $file->get(), $existing);
    }

    /** @return array{date: string, weekday: string, name: string, kind: string, kindLabel: string, state: string, status: string} */
    private function previewLine(HolidayImportRow $row): array
    {
        $date = $row->date === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', $row->date);

        return [
            'date' => $date?->format('d/m/Y') ?? '—',
            // Data de calendário, sem fuso: o dia da semana vem direto dela.
            'weekday' => $date === null ? '' : self::WEEKDAYS[$date->dayOfWeek],
            'name' => $row->name,
            'kind' => $row->kind === null ? '' : $row->kind->value,
            'kindLabel' => $row->kind === null ? '' : $row->kind->label(),
            'state' => $row->error !== null ? 'error' : ($row->exists ? 'exists' : 'new'),
            'status' => $row->error ?? ($row->exists ? 'Já cadastrado' : 'Novo'),
        ];
    }

    private function promptText(): ?string
    {
        $filled = $this->promptCity !== '' && $this->promptUf !== '' && CarbonImmutable::hasFormat($this->promptFrom, 'Y-m-d') && CarbonImmutable::hasFormat($this->promptTo, 'Y-m-d');

        return $filled ? HolidayPrompt::for(trim($this->promptCity), $this->promptUf, $this->promptFrom, $this->promptTo) : null;
    }

    public function render(): View
    {
        return view('livewire.settings.holidays', [
            'holidays' => Holiday::query()->orderBy('date')->get(),
            'prompt' => $this->promptText(),
            'counts' => array_count_values(array_column($this->preview, 'state')),
        ]);
    }
}
