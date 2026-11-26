<x-panel title="Feriados" subtitle="Nas datas em que a empresa fecha o atendimento fica fechado o dia todo.">
    <form wire:submit="addHoliday" class="flex flex-wrap items-end gap-2 border-b border-line p-4">
        <div>
            <flux:input type="date" wire:model="holidayDate" label="Data" />
            @error('holidayDate')
                <p class="mt-1 text-xs text-danger">{{ $message }}</p>
            @enderror
        </div>
        <div class="min-w-40 flex-1">
            <flux:input wire:model="holidayName" label="Nome" maxlength="120" />
            @error('holidayName')
                <p class="mt-1 text-xs text-danger">{{ $message }}</p>
            @enderror
        </div>
        <flux:button type="submit" size="sm">Adicionar</flux:button>
    </form>

    @php
        // O feriado é uma data de calendário (sem hora): o dia da semana vem direto dela, sem fuso.
        $weekdays = [0 => 'dom', 1 => 'seg', 2 => 'ter', 3 => 'qua', 4 => 'qui', 5 => 'sex', 6 => 'sáb'];
    @endphp

    <ul class="text-sm">
        @forelse ($holidays as $holiday)
            <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-line px-4 py-3 last:border-b-0" wire:key="holiday-{{ $holiday->id }}">
                <span class="min-w-0 flex-1">
                    <span class="font-mono text-xs text-ink-3">{{ $holiday->date->format('d/m/Y') }} {{ $weekdays[$holiday->date->dayOfWeek] }}</span> · {{ $holiday->name }}
                </span>
                <x-status-badge :status="$holiday->kind" />
                <flux:switch :checked="$holiday->closes" wire:click="setCloses({{ $holiday->id }}, {{ $holiday->closes ? 'false' : 'true' }})" label="A empresa fecha" />
                <flux:button type="button" size="xs" variant="subtle" icon="trash" wire:click="removeHoliday({{ $holiday->id }})" data-confirm="Remover este feriado?" data-confirm-label="Remover" data-confirm-tone="danger" aria-label="Remover feriado" />
            </li>
        @empty
            <li class="px-4 py-3 text-xs text-ink-3">Nenhum feriado cadastrado.</li>
        @endforelse
    </ul>
</x-panel>
