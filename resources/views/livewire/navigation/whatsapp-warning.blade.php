<div>
    @if ($down)
        {{-- Fechada, o texto some e fica só o ícone, com o mesmo aviso no rótulo e no title. --}}
        <div data-test="whatsapp-down" role="status" class="mx-2 mb-2 rounded-md bg-warn-soft in-data-flux-sidebar-collapsed-desktop:mx-0">
            <p class="px-3 py-2 text-xs text-warn in-data-flux-sidebar-collapsed-desktop:hidden">WhatsApp desconectado. As mensagens ficam aguardando até a conexão voltar.</p>
            <span role="img" aria-label="WhatsApp desconectado" title="WhatsApp desconectado. As mensagens ficam aguardando até a conexão voltar." class="hidden size-10 items-center justify-center text-warn in-data-flux-sidebar-collapsed-desktop:flex">
                <flux:icon.exclamation-triangle class="size-5" />
            </span>
        </div>
    @endif
</div>
