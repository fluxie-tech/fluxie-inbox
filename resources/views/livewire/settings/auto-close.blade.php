<x-panel title="Encerramento automático" subtitle="Encerra o chamado parado quando a última mensagem foi nossa. Chamado na fila e cliente esperando resposta nunca encerram sozinhos.">
    <form wire:submit="save">
        <div data-switch-row class="border-b border-line px-4 py-3">
            <flux:switch wire:model="enabled" label="Encerrar chamados parados" />
        </div>

        <div class="space-y-5 p-4">
            <div>
                <p class="text-sm font-medium text-ink">Tempo parado até avisar (ou encerrar, sem aviso)</p>
                <div class="mt-2 flex max-w-xs gap-2">
                    <flux:input type="number" min="0" wire:model="idleHours" label="Horas" />
                    <flux:input type="number" min="0" wire:model="idleMinutes" label="Minutos" />
                </div>
                @error('idleMinutes')
                    <p class="mt-1 text-xs text-danger" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <flux:switch wire:model="businessHoursOnly" label="Contar só o horário de atendimento" description="Conta só o tempo dentro do horário de atendimento; o aviso e a despedida também só saem nesse horário." />

            <div class="space-y-3 border-t border-line pt-4">
                <flux:switch wire:model="warnEnabled" label="Avisar antes de encerrar" />
                <flux:textarea wire:model.live.debounce.400ms="warnMessage" rows="3" label="Texto do aviso" />
                <p class="text-xs text-ink-3">Use @{{nome}} e @{{protocolo}} no texto.</p>
                @error('warnMessage')
                    <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                @enderror
                <p class="text-xs text-ink-3">Prévia</p>
                <p class="rounded-md bg-accent-soft px-3 py-2 text-sm whitespace-pre-wrap text-ink">{{ $warnPreview }}</p>

                <p class="text-sm font-medium text-ink">Espera entre o aviso e o encerramento</p>
                <div class="flex max-w-xs gap-2">
                    <flux:input type="number" min="0" wire:model="afterWarnHours" label="Horas" />
                    <flux:input type="number" min="0" wire:model="afterWarnMinutes" label="Minutos" />
                </div>
                @error('afterWarnMinutes')
                    <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-3 border-t border-line pt-4">
                <flux:switch wire:model="farewellEnabled" label="Enviar despedida ao encerrar" />
                <flux:textarea wire:model.live.debounce.400ms="farewellMessage" rows="3" label="Texto da despedida" />
                <p class="text-xs text-ink-3">Use @{{nome}} e @{{protocolo}} no texto.</p>
                @error('farewellMessage')
                    <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                @enderror
                <p class="text-xs text-ink-3">Prévia</p>
                <p class="rounded-md bg-accent-soft px-3 py-2 text-sm whitespace-pre-wrap text-ink">{{ $farewellPreview }}</p>
            </div>
        </div>

        <div data-panel-footer class="flex items-center gap-3 border-t border-line px-4 py-3">
            <flux:button type="submit" variant="primary" size="sm">Salvar</flux:button>
            @if (session('saved-auto-close'))
                <p class="text-xs text-ok" role="status">{{ session('saved-auto-close') }}</p>
            @endif
        </div>
    </form>
</x-panel>
