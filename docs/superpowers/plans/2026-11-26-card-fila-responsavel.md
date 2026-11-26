# Card da fila: responsável na mesma linha do selo

Pedido do dono (26/11/2026, print): no card da fila a linha de baixo (selo + protocolo + "JP Jessé") não cabe na coluna e o
responsável desce sozinho, sobrando espaço à esquerda. Decisão do dono: tudo numa linha; com a coluna estreita o responsável
mostra só a foto/iniciais (nome no `title` e no `sr-only`); com a coluna larga, a foto e o primeiro nome.

- [ ] RED em `tests/Feature/Livewire/InboxQueueCardTest.php`: o conteúdo do card é um contêiner (`@container`); o primeiro nome
  do responsável fica oculto por padrão e aparece só com o contêiner largo (`@[17rem]:inline`); o nome completo continua no
  `title` e no `sr-only`.
- [ ] Implementar em `resources/views/livewire/inbox/inbox.blade.php` (card, linhas ~138 e ~180).
- [ ] `npm run build`, suíte do card e portão 7; conferência no staging com o Claude in Chrome (1280, 1920 e 2560).
