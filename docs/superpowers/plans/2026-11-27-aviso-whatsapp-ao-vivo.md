# Aviso "WhatsApp desconectado" do menu lateral ao vivo

Print do dono (27/11/2026): o aviso do menu lateral ("WhatsApp desconectado. As mensagens ficam aguardando até a conexão
voltar.") só some quando se clica numa opção do menu, não quando o WhatsApp volta. A causa é a mesma dos selos da Fila (#171):
`resources/views/layouts/app/sidebar.blade.php:53-61` lê `SidebarState::whatsappDown()` só ao montar o layout.

- Componente Livewire novo, `App\Livewire\Navigation\WhatsappWarning` (view `livewire/navigation/whatsapp-warning.blade.php`):
  - Renderiza exatamente o bloco de hoje (`data-test="whatsapp-down"`, `role="status"`, texto, ícone e `title` iguais) quando
    `SidebarState::whatsappDown()` é verdadeiro.
  - Quando é falso, renderiza só a raiz vazia (`<div></div>`), sem texto.
  - Listeners (molde de `App\Livewire\Alerts\Banner` e `App\Livewire\Navigation\QueueItem`):
    - `echo-private:inbox,.channel.status` → `$refresh`;
    - `realtime-reconnected` → `$refresh`;
    - `alerts-refresh` → `$refresh`.
- O layout troca o `@if (...)` pelo `<livewire:navigation.whatsapp-warning />` no mesmo lugar (depois do `flux:spacer`).
- Testes: os existentes do aviso no menu (`whatsapp-down`) continuam valendo pelo componente. Teste novo: com o canal fora do ar o
  aviso aparece, e depois de o canal voltar (`WORKING`) um `$refresh` do componente tira o aviso. Nada visual muda.
- Portão 7 (Pint, PHPStan, PHPMD sem baseline, `node --test`, build e suíte com Redis).
