# Sino marca como lido depois de 3 s aberto e selos da Fila ao vivo no menu

Pedido do dono (27/11/2026).

## 1. Sino (`App\Livewire\Alerts\Bell`, `resources/views/livewire/alerts/bell.blade.php`, `resources/js/alert-ui.js`)

Decisões do dono: com o sino aberto por **3 s**, todos os alertas não lidos viram lidos e o contador zera; o destaque de não lido
(o ponto `bg-accent` e o "não lido" para leitor de tela) dos alertas que eram novos **continua enquanto o sino estiver aberto** e
só some ao fechar. "Marcar todas como lidas" e o clique num alerta continuam como estão.

- `Bell::seenWhileOpen(): void` marca todos os não lidos do usuário como lidos (como `markAllRead`) e guarda os ids que eram não
  lidos e aparecem na lista em `public array $held = []` (`#[Locked]`). Um alerta cujo id está em `$held` continua com o destaque.
- `Bell::releaseHeld(): void` limpa `$held`.
- Contador e o texto "N novos" usam só os não lidos de verdade (zeram depois dos 3 s).
- JS (`alertBell`): ao abrir, agenda `this.$wire.seenWhileOpen()` em 3000 ms (constante `SEEN_AFTER_MS`); fechar antes cancela o
  timer; ao fechar (clique fora, Esc, botão), chama `this.$wire.releaseHeld()` se o timer chegou a disparar. Subconjunto CSP do
  Alpine (`tests/Unit/AlpineCspSubsetTest.php`): a lógica fica no componente registrado, não em expressões no Blade.
- Testes: `tests/Feature/Alertas/BellTest.php` (seenWhileOpen zera o contador e mantém o destaque dos que eram novos; releaseHeld
  tira o destaque; alerta de outro usuário intacto) e `tests/js` (timer de 3 s agenda e cancela; fechar libera).

## 2. Selos da Fila no menu ao vivo (`resources/views/layouts/app/sidebar.blade.php`)

Causa: o menu é Blade do layout, montado só ao carregar ou navegar; `$counts` (aguardando e SLA estourado) não muda depois. As
faixas (`Alerts\Banner`) já escutam `echo-private:inbox,.ticket.updated`.

- Componente Livewire novo `App\Livewire\Navigation\QueueItem` (view `livewire/navigation/queue-item.blade.php`) que renderiza
  exatamente o item "Fila" de hoje (mesmo `flux:sidebar.item`, selo amarelo `queue-badge`, selo vermelho `sla-badge`, ponto
  `queue-dot`, `aria-label` com as contagens, `wire:navigate`, `:current`), com listeners `echo-private:inbox,.ticket.updated`,
  `echo-private:users.{id},.Illuminate\Notifications\Events\BroadcastNotificationCreated`, `realtime-reconnected` e
  `alerts-refresh` → `$refresh`. A contagem vem da mesma fonte que o layout usa hoje para `$counts`.
- O `@foreach` do layout pula o item `inbox` e inclui `<livewire:navigation.queue-item />` no lugar (mesma posição).
- Nada visual muda. Testes: os existentes do menu (`queue-badge`, `sla-badge`, `queue-dot`, aria-label) passam a valer para o
  componente; teste novo: depois de o ticket deixar de estar estourado, um `$refresh` do componente tira o selo vermelho.

- [ ] RED/GREEN de cada item; portão 7 (Pint, PHPStan, PHPMD sem baseline, `node --test tests/js`, build, suíte com Redis).
