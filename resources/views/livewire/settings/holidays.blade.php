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

    <div class="space-y-4 border-t border-line p-4">
        <h3 class="text-sm font-medium text-ink">Importar feriados</h3>

        <div class="space-y-2">
            <p class="text-xs text-ink-3">1. Informe a cidade e o período para gerar o pedido para a IA.</p>
            <div class="flex flex-wrap items-end gap-2">
                <div class="min-w-40 flex-1">
                    <flux:input wire:model.live.debounce.400ms="promptCity" label="Cidade" maxlength="80" />
                </div>
                <div class="w-20">
                    <flux:input wire:model.live.debounce.400ms="promptUf" label="UF" maxlength="2" />
                </div>
                <flux:input type="date" wire:model.live="promptFrom" label="De" />
                <flux:input type="date" wire:model.live="promptTo" label="Até" />
            </div>
            @if ($prompt !== null)
                <div x-data="copyKey" class="space-y-2">
                    <flux:textarea x-ref="prompt" readonly rows="7" aria-label="Pedido para a IA">{{ $prompt }}</flux:textarea>
                    <flux:button type="button" size="sm" icon="document-duplicate" @click="copy($refs.prompt.value)">
                        <span x-text="copied ? 'Copiado' : 'Copiar'">Copiar</span>
                    </flux:button>
                </div>
            @endif
        </div>

        <div class="space-y-2">
            <p class="text-xs text-ink-3">2. Cole o prompt em qualquer IA gratuita, salve a resposta como arquivo .json e envie aqui.</p>
            <flux:input type="file" accept=".json,application/json" wire:model="importFile" aria-label="Arquivo de feriados" />
            @error('importFile')
                <p class="text-xs text-danger">{{ $message }}</p>
            @enderror
            @if ($importError)
                <p class="text-xs text-danger">{{ $importError }}</p>
            @endif
            @if ($imported !== null)
                <p class="text-xs text-ok">{{ $imported === 0 ? 'Nenhum feriado novo para importar.' : $imported.($imported === 1 ? ' feriado importado.' : ' feriados importados.') }}</p>
            @endif
        </div>

        @if ($preview !== [])
            @php($new = $counts['new'] ?? 0)
            <div class="space-y-2">
                <p class="text-xs text-ink-3">3. Confira antes de importar.</p>
                <p class="text-sm text-ink">{{ $new }} novos, {{ $counts['exists'] ?? 0 }} já cadastrados, {{ $counts['error'] ?? 0 }} com erro</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs text-ink-3">
                            <tr>
                                <th class="py-1 pr-3 font-medium">Data</th>
                                <th class="py-1 pr-3 font-medium">Nome</th>
                                <th class="py-1 pr-3 font-medium">Tipo</th>
                                <th class="py-1 font-medium">Situação</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($preview as $line)
                                <tr class="border-t border-line" wire:key="preview-{{ $loop->index }}">
                                    <td class="whitespace-nowrap py-1.5 pr-3 font-mono text-xs text-ink-3">{{ $line['date'] }} {{ $line['weekday'] }}</td>
                                    <td class="py-1.5 pr-3">{{ $line['name'] }}</td>
                                    <td class="py-1.5 pr-3">
                                        @if ($line['kind'] !== '')
                                            <x-status-badge :status="\App\Enums\HolidayKind::from($line['kind'])" />
                                        @endif
                                    </td>
                                    <td class="py-1.5 text-xs {{ $line['state'] === 'error' ? 'text-danger' : 'text-ink-2' }}">{{ $line['status'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="flex gap-2">
                    @if ($new > 0)
                        <flux:button type="button" size="sm" variant="primary" wire:click="import">Importar {{ $new }} {{ $new === 1 ? 'feriado' : 'feriados' }}</flux:button>
                    @endif
                    <flux:button type="button" size="sm" variant="ghost" wire:click="cancelImport">Cancelar</flux:button>
                </div>
            </div>
        @endif
    </div>
</x-panel>
