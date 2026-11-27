# Card da aba Encerrados: data numa linha só

Conferência do staging (27/11/2026, chrome-devtools): na aba Encerrados da fila a data/hora do encerramento ("27/11 09:57")
quebra em duas linhas quando o nome do contato é longo, porque o nome não encolhe e a data não tem `shrink-0`. Dono pediu a
correção. Mesmo padrão do card da fila ativa: nome `min-w-0 flex-1 truncate`, hora `shrink-0 whitespace-nowrap`.

- [ ] RED em `tests/Feature/Livewire/InboxQueueCardTest.php`: a hora do card encerrado (`data-testid="closed-card-time"`) é
  `shrink-0` e `whitespace-nowrap`, e o nome é `min-w-0 flex-1 truncate`.
- [ ] Implementar em `resources/views/livewire/inbox/inbox.blade.php` (~linhas 79–81).
- [ ] Suíte do card e portão 7.
