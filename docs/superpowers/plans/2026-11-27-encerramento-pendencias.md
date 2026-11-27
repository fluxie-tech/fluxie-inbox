# Encerramento: aviso na linha do tempo, largura da tela e nome do atendente no card

Pendências da conferência do staging (rc.10, 27/11/2026). Decisões do dono (27/11):

1. **Linha do tempo.** Quando o aviso do encerramento automático sai, grava-se o evento `auto_close_warned` no ticket (sem payload).
   O encerramento automático passa a gravar `farewell: true` no payload do evento `closed` quando a despedida foi criada.
   - `TicketEventText::TYPES` ganha `auto_close_warned`. `TicketTimeline` mostra
     "Automação avisou o cliente do encerramento por inatividade" (evento de sistema, sem ator).
   - `closedDetail` acrescenta "despedida enviada" quando `farewell` é verdadeiro: "por inatividade · despedida enviada".
   - Valem a linha do tempo do histórico e a Auditoria.
   - A despedida é criada depois do `CloseTicket`. Gravar `farewell` atualizando o payload do evento `closed` recém-criado
     (o mais recente do ticket, `type = closed`), só quando a mensagem foi criada.
   - Testes em `tests/Feature/Console/CloseInactiveTicketsTest.php` e em `tests/Feature/Timeline/TicketTimelineTest.php`.
2. **Tela Encerramento.** `resources/views/livewire/settings/closing.blade.php` deixa de usar `width="wide"` e passa à largura de
   formulário (padrão do `x-settings-page`, `max-w-4xl`). Teste: a página não tem a classe de largura toda (molde dos testes
   de `x-settings-page` existentes).
3. **Card da fila.** Em `resources/views/livewire/inbox/inbox.blade.php:7`, a coluna da fila passa de 340 px para 380 px a
   partir de `2xl` (1536 px), nos dois casos da grade (com e sem painel de detalhes). Em `lg` continua 340 px. O nome do
   atendente (`@[17rem]:inline`) passa a aparecer em 1080p e 2K. Teste: a grade tem `2xl:grid-cols-[380px_...]`.

- [ ] RED/GREEN de cada item; `npm run build`; portão 7 (Pint, PHPStan, PHPMD, `node --test`, build, suíte com Redis).
- [ ] Conferência no staging pelo chrome-devtools em 1280x720, 1920x1080 e 2560x1440 depois da pré-release.
