<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-surface">
        {{-- --sidebar-bg: fundo da sidebar, também usado no anel do ponto da Fila (uma só fonte para as duas cores). --}}
        <flux:sidebar sticky collapsible class="border-e border-line bg-(--sidebar-bg) [--sidebar-bg:var(--color-canvas)]">
            <flux:sidebar.header class="in-data-flux-sidebar-collapsed-desktop:flex-col in-data-flux-sidebar-collapsed-desktop:items-center in-data-flux-sidebar-collapsed-desktop:gap-3">
                {{-- Fechada, o Flux tira a marca do fluxo no hover e só então mostra o botão de colapso: sem mouse (toque, teclado) não haveria como
                     reabrir, e o sino pulava de lugar. Aqui a marca fica fixa e o botão fica sempre à vista. A ordem do DOM é a ordem visual nos dois
                     estados (marca, botão, sino): o Tab segue o que se vê. --}}
                <x-app-logo :sidebar="true" href="{{ route('inbox') }}" aria-label="{{ \App\Settings\CompanySettings::logoUrl() === null ? mb_strtoupper(mb_substr(trim(\App\Settings\CompanySettings::name()), 0, 1)).' ' : '' }}{{ trim(\App\Settings\CompanySettings::name()) }}" wire:navigate class="in-data-flux-sidebar-collapsed-desktop:in-data-flux-sidebar-active:static! in-data-flux-sidebar-collapsed-desktop:in-data-flux-sidebar-active:opacity-100!" />
                <flux:sidebar.collapse tooltip="Recolher ou expandir menu" class="in-data-flux-sidebar-collapsed-desktop:static! in-data-flux-sidebar-collapsed-desktop:opacity-100!" />
                <livewire:alerts.bell />
            </flux:sidebar.header>

            @php
                $nav = app(\App\Support\Navigation::class);
                $entry = $nav->settingsEntry(auth()->user());
            @endphp

            <flux:sidebar.nav>
                {{-- Busca Ctrl+K (resources/js/command-palette.js): o atalho abre o mesmo modal. --}}
                <flux:sidebar.item icon="magnifying-glass" as="button" type="button" data-palette-open aria-label="Buscar" aria-keyshortcuts="Control+K Meta+K" data-test="palette-open">
                    Buscar
                    <x-slot:icon-trailing><span data-palette-hint aria-hidden="true" class="font-mono text-xs text-ink-3 in-data-flux-sidebar-collapsed-desktop:hidden">Ctrl K</span></x-slot:icon-trailing>
                </flux:sidebar.item>

                @foreach ($nav->primary(auth()->user()) as $item)
                    {{-- A Fila é um componente próprio: os selos se atualizam ao vivo (Livewire), o resto do menu só ao navegar. --}}
                    @if ($item->route === 'inbox')
                        <livewire:navigation.queue-item :current="request()->routeIs($item->match)" />
                    @else
                        <flux:sidebar.item :href="route($item->route)" :current="request()->routeIs($item->match)" aria-label="{{ $item->label }}" wire:navigate>
                            <x-slot:icon>
                                <flux:icon :icon="$item->icon" class="size-4" />
                            </x-slot:icon>
                            {{ $item->label }}
                        </flux:sidebar.item>
                    @endif
                @endforeach

                @if ($entry !== null)
                    <flux:sidebar.item icon="cog-6-tooth" :href="route($entry->route)" :current="$nav->settingsActive(auth()->user())" class="mt-3" data-test="settings-entry" aria-label="Configuração" wire:navigate>
                        Configuração
                    </flux:sidebar.item>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            @if (app(\App\Support\SidebarState::class)->whatsappDown())
                {{-- Fechada, o texto some e fica só o ícone, com o mesmo aviso no rótulo e no title. --}}
                <div data-test="whatsapp-down" role="status" class="mx-2 mb-2 rounded-md bg-warn-soft in-data-flux-sidebar-collapsed-desktop:mx-0">
                    <p class="px-3 py-2 text-xs text-warn in-data-flux-sidebar-collapsed-desktop:hidden">WhatsApp desconectado. As mensagens ficam aguardando até a conexão voltar.</p>
                    <span role="img" aria-label="WhatsApp desconectado" title="WhatsApp desconectado. As mensagens ficam aguardando até a conexão voltar." class="hidden size-10 items-center justify-center text-warn in-data-flux-sidebar-collapsed-desktop:flex">
                        <flux:icon.exclamation-triangle class="size-5" />
                    </span>
                </div>
            @endif

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <x-avatar :src="auth()->user()->avatarUrl()" :name="auth()->user()->name" shape="round" decorative />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            Minha conta
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <x-appearance-switcher />

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            Sair
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        <x-confirm-dialog />

        <dialog id="command-palette" aria-label="Busca" class="m-auto mt-[12vh] w-[calc(100vw-2rem)] max-w-lg rounded-xl border border-line bg-surface p-4 text-ink shadow-xl backdrop:bg-black/40">
            <livewire:search.palette />
        </dialog>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
