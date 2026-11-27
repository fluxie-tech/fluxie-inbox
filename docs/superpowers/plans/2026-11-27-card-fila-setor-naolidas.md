# Card da fila: setor nas etiquetas e não lidas na linha da mensagem

Conferência do staging na rc.11 (27/11/2026). Com o selo de setor (`card-sector`) ou o contador de não lidas (`card-unread`) na
linha de baixo do card, o grupo da direita quebra para uma segunda linha e sobra espaço à esquerda, que é o mesmo defeito do #168.
Isso vale nas três resoluções. Decisão do dono: o setor vira chip na linha das etiquetas e as não lidas sobem; a linha de baixo
fica só com selo, protocolo e atendente, e nunca quebra.

Arquivo: `resources/views/livewire/inbox/inbox.blade.php`, no card da fila ativa (o `@foreach ($group as $item)`). O card da aba
Encerrados não muda.

- **Setor (`card-sector`):**
  - Vai para a linha das etiquetas (`mt-0.5 flex ... flex-wrap`), como o primeiro chip, antes das etiquetas.
  - Mantém o visual de hoje (`rounded bg-canvas px-1.5 py-0.5 text-xs text-ink-2`, `max-w-24 truncate`).
  - A linha das etiquetas passa a existir também quando o card tem só o setor.
  - Continua só com `$showSectorChip`.
- **Não lidas (`card-unread`):**
  - Vão para a direita da linha da última mensagem (a do `preview`, `card-preview` se precisar de um marcador).
  - O texto da mensagem trunca e o contador fica `shrink-0`, como no WhatsApp.
  - Mantém o visual e o `sr-only` de hoje.
- **Linha de baixo:**
  - Fica com `card-status`, `card-protocol` e o atendente.
  - O atendente fica num grupo `ms-auto shrink-0`, e o nome continua com `hidden @[17rem]:inline`.
  - A linha passa a ser `flex-nowrap` (sem `flex-wrap`).
  - `card-extras` passa a envolver só o atendente e só existe com `$showAssignee`.
- **Testes:** atualizar `tests/Feature/Livewire/InboxQueueCardTest.php`:
  - setor dentro da linha das etiquetas;
  - não lidas na linha da mensagem;
  - linha de baixo sem `flex-wrap`;
  - ordem de leitura: hora, setor, mensagem, não lidas, atendente.
  - Os demais testes que dependem de `card-extras` mudam de acordo.
- Portão 7 completo e conferência no staging em 1280x720, 1920x1080 e 2560x1440, com setor, atendente e não lidas juntos.
