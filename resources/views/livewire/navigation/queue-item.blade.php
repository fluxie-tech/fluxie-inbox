<div>
    @if ($item !== null)
        @php
            // Fechada, o texto do item some e o `aria-label` vira o nome do link; ele substitui o conteúdo, então leva as contagens do selo.
            // O nome COMEÇA pelo texto visível (a Fila e os números dos selos, nessa ordem): WCAG 2.5.3, nome acessível contém o rótulo visível.
            $name = $item->label;

            if ($counts['waiting'] > 0 || $counts['breached'] > 0) {
                $details = array_filter([
                    $counts['waiting'] > 0 ? $counts['waiting'].' aguardando' : null,
                    $counts['breached'] > 0 ? $counts['breached'].' com SLA estourado' : null,
                ]);
                $name = implode(' ', array_filter([$item->label, $counts['breached'] ?: null, $counts['waiting'] ?: null])).': '.implode(', ', $details);
            }
        @endphp
        <flux:sidebar.item :href="route($item->route)" :current="request()->routeIs($item->match)" aria-label="{{ $name }}" wire:navigate
            :badge="$counts['waiting'] ?: null" badge-color="yellow"
            badge:data-test="queue-badge">
            {{-- Fechada, o Flux esconde badge e selo: o estado da Fila vai num ponto sobre o ícone (vermelho só com SLA estourado). --}}
            <x-slot:icon>
                <flux:icon :icon="$item->icon" class="size-4" />
                @if ($counts['breached'] > 0 || $counts['waiting'] > 0)
                    <span data-test="queue-dot" aria-hidden="true" class="absolute -end-0.5 -top-0.5 hidden size-2.5 rounded-full ring-2 ring-(--sidebar-bg) in-data-flux-sidebar-collapsed-desktop:block {{ $counts['breached'] > 0 ? 'bg-danger' : 'bg-accent' }}"></span>
                @endif
            </x-slot:icon>
            @if ($counts['breached'] > 0)
                <x-slot:icon-trailing>
                    <span data-test="sla-badge" aria-label="{{ $counts['breached'] }} com SLA estourado" class="rounded-full bg-danger px-1.5 text-xs font-semibold text-surface in-data-flux-sidebar-collapsed-desktop:hidden">{{ $counts['breached'] }}</span>
                </x-slot:icon-trailing>
            @endif
            {{ $item->label }}
        </flux:sidebar.item>
    @endif
</div>
