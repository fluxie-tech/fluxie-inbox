<x-settings-page route="configuracoes.encerramento" title="Encerramento" subtitle="Os motivos aparecem no Encerrar, nesta ordem. Desativado sai do Encerrar e continua no histórico. Só dá para apagar um motivo que nunca foi usado." width="wide">
    <div class="mt-6 space-y-5">
        <x-panel title="Novo motivo">
            <form wire:submit="create($wire.newName)" class="flex flex-wrap items-end gap-2 p-4">
                <div class="min-w-48 flex-1">
                    <flux:input wire:model="newName" label="Nome" placeholder="Ex.: Orçamento enviado" maxlength="60" autocomplete="off" />
                </div>
                <flux:button type="submit" variant="primary" size="sm" icon="plus">Criar motivo</flux:button>
            </form>
        </x-panel>

        <x-panel title="Motivos de encerramento" subtitle="Na ordem do Encerrar">
            <ul>
                @foreach ($reasons as $reason)
                    <li class="border-b border-line px-4 py-3 last:border-b-0" wire:key="reason-{{ $reason->id }}">
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="flex flex-col">
                                <flux:button size="xs" variant="subtle" icon="chevron-up" wire:click="move({{ $reason->id }}, 'up')" :disabled="$loop->first" aria-label="Subir {{ $reason->name }}" />
                                <flux:button size="xs" variant="subtle" icon="chevron-down" wire:click="move({{ $reason->id }}, 'down')" :disabled="$loop->last" aria-label="Descer {{ $reason->name }}" />
                            </div>

                            <div class="min-w-36 flex-1" x-data="sectorRow(@js($reason->name))">
                                <p x-show="! editing" class="flex items-center gap-1 text-sm font-semibold text-ink">
                                    <span class="me-1 font-mono text-xs text-ink-3">{{ $loop->iteration }}</span>
                                    <span class="truncate">{{ $reason->name }}</span>
                                    <flux:button size="xs" variant="subtle" icon="pencil-square" class="min-h-6! min-w-6!" x-on:click="start()" aria-label="Renomear {{ $reason->name }}" />
                                </p>
                                <form x-show="editing" x-cloak class="flex items-center gap-1" x-on:submit.prevent="save({{ $reason->id }})" x-on:keydown.escape="cancel(@js($reason->name))">
                                    <input x-ref="field" x-model="name" maxlength="60" aria-label="Novo nome de {{ $reason->name }}" class="min-w-0 flex-1 rounded-lg border border-line bg-surface px-2 py-1 text-sm text-ink">
                                    <flux:button type="submit" size="xs" variant="primary">Salvar</flux:button>
                                    <flux:button type="button" size="xs" variant="subtle" x-on:click="cancel(@js($reason->name))">Cancelar</flux:button>
                                </form>
                            </div>

                            <div wire:key="summary-{{ $reason->id }}-{{ $refusals }}">
                                <flux:switch :checked="$reason->requires_summary" wire:click="toggleSummary({{ $reason->id }}, {{ $reason->requires_summary ? 'false' : 'true' }})" label="Pede resumo" />
                            </div>
                            <div wire:key="active-{{ $reason->id }}-{{ $refusals }}">
                                <flux:switch :checked="$reason->active" wire:click="setActive({{ $reason->id }}, {{ $reason->active ? 'false' : 'true' }})" label="Ativo" />
                            </div>

                            <flux:button size="xs" variant="subtle" icon="trash" wire:click="remove({{ $reason->id }})" aria-label="Apagar {{ $reason->name }}" />
                        </div>

                        @foreach (['rename', 'active', 'remove'] as $key)
                            @error($key.'.'.$reason->id)
                                <p class="mt-2 text-xs text-danger" role="alert">{{ $message }}</p>
                            @enderror
                        @endforeach
                    </li>
                @endforeach
            </ul>
        </x-panel>
    </div>
</x-settings-page>
