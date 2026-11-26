# Acoplamento — fatia 1 (Inbox) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** tirar do `phpmd.baseline.xml` as cinco classes da Inbox (`StartConversation` 19, `AlertDispatcher` 15,
`Presence` 14, `SendTicketMessage` 20, `IngestMessage` 27), cada uma com CBO ≤ 13, sem mudar comportamento.

**Architecture:** cada classe perde responsabilidades inteiras para classes novas no mesmo namespace; os chamadores passam
a usar a classe nova (sem fachada de passagem). A suíte atual é a rede: ela não muda o que afirma.

**Tech Stack:** Laravel 13, PHP 8.4, PHPMD 3.0.0, PHPStan + PHPat, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-25-refatoracao-acoplamento-design.md`

## Global Constraints

- CBO ≤ 13 em toda classe criada ou tocada que não esteja no baseline; as do baseline não sobem sem registro no ledger.
- Proibido baixar o número com trait, apagando tipo (type hint, `@return`/`@throws`/`@var`), `mixed`/`object`, classe por
  string ou classe de passagem (spec, "Antiobjetivos").
- Classe nova em `App\Services\Inbox` (serviço) ou `App\Actions\Inbox` (ação); nenhuma recebe tipo de `App\Livewire`/`App\Http`.
- Comportamento, textos de erro e constantes idênticos; testes existentes só mudam na linha que chama algo que mudou de lugar.
- Comentários existentes (o "porquê") viajam com o código movido. Sem supressão de CBO nesta fatia.
- Commits em português com prefixo `refactor:`, terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Medição (todas as tarefas)

- Com baseline (o que reprova): `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml --baseline-file=phpmd.baseline.xml`
- Valor de qualquer classe: `vendor/bin/phpmd analyze <arquivos separados por vírgula> --format text --ruleset .superpowers/phpmd-cbo.xml`
  (máximo 0: lista o CBO de todas; o arquivo é local e ignorado pelo git).
- Testes afetados: `git status --porcelain | cut -c4- | codegraph affected --stdin -d 2 --quiet | xargs -r php artisan test`
- Tipos: `vendor/bin/phpstan analyse --no-progress` (inclui as regras do PHPat).
- RED de cada tarefa: apagar a linha da classe no `phpmd.baseline.xml` e ver o PHPMD com baseline reprovar com o valor atual.
  As classes novas não ganham teste próprio quando a lógica movida já é exercida pelos testes do chamador (listados em cada
  tarefa); duplicar não é pedido.
- Fim de cada tarefa: anotar no relatório o CBO antes/depois da classe, das classes novas e dos chamadores tocados.

## Review Focus

1. Eco `fromMe` chegando antes da resposta HTTP: casa com a linha pendente (janela 60 s painel, 600 s bot) e não duplica —
   `IngestMessageTest::test_eco_completa_o_id_da_mensagem_pendente_do_painel_sem_duplicar`,
   `test_eco_da_resposta_automatica_e_adotado_e_nao_vira_fora_do_painel` (Tarefa 5).
2. Duas mensagens simultâneas do mesmo contato: o `pg_advisory_xact_lock` continua na mesma transação e antes da busca do
   ticket aberto (Tarefa 5) e na abertura pelo atendente (Tarefa 1) — `StartConversationTest::test_duas_chamadas_seguidas_geram_um_ticket_so`.
3. Bot e resposta fora do horário só depois do commit (`DB::afterCommit`), nunca para a mensagem que perdeu a corrida —
   `AfterHoursReplyTest`, `tests/Feature/Bot/IngestIntegrationTest.php` (Tarefa 5).
4. Ordem da `StartConversation`: guard → ticket aberto → número → ritmo → transação; o ritmo só volta quando o setor
   sumiu — `test_recusa_devolve_so_o_proprio_ritmo_e_nao_o_de_outra_conversa_no_canal` (Tarefa 1).
5. Queda do canal anunciada uma vez por episódio e volta só se a queda foi anunciada — testes de queda/volta do canal
   (`test_queda_confirma_depois_de_60s`, `test_volta_so_avisa_se_a_queda_foi_anunciada`) (Tarefa 2).

---

### Task 1: `StartConversation` (cronometrada)

**Files:**
- Create: `app/Services/Inbox/ConversationPace.php`, `app/Services/Inbox/WhatsAppNumber.php`, `app/Actions/Inbox/OpenTicketByAgent.php`
- Modify: `app/Actions/Inbox/StartConversation.php`, `app/Livewire/Inbox/NewConversation.php:130-147`, `phpmd.baseline.xml`
- Test: `tests/Feature/Inbox/StartConversationTest.php`, `tests/Feature/Livewire/NewConversationModalTest.php` (sem mudar asserções)

**Interfaces:**
- Produces:
  - `ConversationPace::reserve(Contact $contact, Channel $channel): void` (`@throws ConversationNotStarted`, mesma mensagem
    "Aguarde N s…") e `ConversationPace::release(Contact $contact, Channel $channel): void`; levam `CHANNEL_GAP_SECONDS = 30`,
    `CONTACT_PER_HOUR = 4`, a janela de 3600 s e as chaves `start-conversation:channel:{id}` / `start-conversation:contact:{id}`.
  - `WhatsAppNumber::assertExists(string $waId, Channel $channel): void` (`@throws ConversationNotStarted`, as duas mensagens
    atuais de `assertHasWhatsApp`). `StartConversation::assertHasWhatsApp` deixa de existir.
  - `OpenTicketByAgent::__invoke(User $user, Contact $contact, Channel $channel, Sector $sector, string $text): Message` —
    cria o ticket `EM_ATENDIMENTO`, o `TicketEvent` `opened_by_agent` e a primeira mensagem (`SendTicketMessage::create` com
    `Signature::apply`); roda dentro da transação de quem chama.
  - `OpenTicketByAgent::announce(Message $message, Contact $contact, Channel $channel): void` — depois do commit: fila a foto
    (`SyncContactPhoto::queueFor`), `SendTicketMessageJob::dispatch`, `TicketUpdated::dispatch` do ticket da mensagem.
  - `StartConversation::__invoke` mantém assinatura e retorno; `TEXT_LIMIT` fica nela.

- [ ] **Step 1:** Anotar no relatório a hora de início (é a tarefa cronometrada).
- [ ] **Step 2 (RED):** apagar a linha de `app/Actions/Inbox/StartConversation.php` no `phpmd.baseline.xml`; rodar o PHPMD com
  baseline. Esperado: reprova, "StartConversation … value of 19".
- [ ] **Step 3:** criar as três classes movendo o código atual (corpos e comentários) e fazer a `StartConversation` usá-las,
  injetadas pelo construtor; a `NewConversation::newContact` passa a receber `WhatsAppNumber` em vez de `StartConversation`.
- [ ] **Step 4 (GREEN):** PHPMD com baseline passa; medir `StartConversation`, as três novas e `NewConversation` (esta segue
  no baseline; anotar o valor). Esperado: as quatro primeiras ≤ 13.
- [ ] **Step 5:** `php artisan test tests/Feature/Inbox/StartConversationTest.php tests/Feature/Livewire/NewConversationModalTest.php`
  e os testes afetados; PHPStan. Esperado: tudo verde, sem mudar asserção.
- [ ] **Step 6:** commit `refactor: StartConversation sem acoplamento excessivo (ritmo, número e abertura em classes próprias)`.

### Task 2: `AlertDispatcher` → `ChannelAlerts` + `TicketAlerts`

**Files:**
- Create: `app/Services/Alerts/ChannelAlerts.php`, `app/Services/Alerts/TicketAlerts.php`
- Delete: `app/Services/Alerts/AlertDispatcher.php`
- Modify: `app/Observers/ChannelObserver.php`, `app/Jobs/ConfirmChannelDownJob.php`, `app/Services/Waha/ChannelPhone.php`,
  `app/Actions/Inbox/ReassignTicket.php`, `app/Actions/Inbox/TransferTicket.php`, `app/Services/Inbox/SlaClock.php`,
  `app/Services/Inbox/IngestMessage.php:296`, `app/Services/Bot/Effects/LiveEffects.php:169,236,265`,
  `tests/Feature/Livewire/InactiveChannelTest.php:18,52`, `phpmd.baseline.xml`
- Test: os de queda/volta do canal, SLA, fila e atribuição (achar com `codegraph affected`)

**Interfaces:**
- Produces:
  - `final class ChannelAlerts`: `GRACE_SECONDS = 60`; `channelChanged(Channel $channel, ChannelStatus $from): void`,
    `confirmDown(Channel $channel): void`, `numberChanged(Channel $channel): void`, `announceUp` privado. Construtor recebe
    `AlertRecipients`.
  - `final class TicketAlerts`: `sla(Ticket $ticket, bool $breached): void`, `assigned(Ticket $ticket, User $to, ?User $actor): void`,
    `queued(Ticket $ticket, ?int $exceptUserId = null): void`. Construtor recebe `AlertRecipients`.
  - Docblock de classe de cada uma diz o que antes o `AlertDispatcher` dizia ("o único lugar que transforma um fato… em alertas").

- [ ] **Step 1 (RED):** apagar a linha do `AlertDispatcher` no baseline; PHPMD com baseline reprova com 15.
- [ ] **Step 2:** criar as duas classes com os métodos atuais; trocar cada chamador pela classe certa
  (`ConfirmChannelDownJob` usa `ChannelAlerts::GRACE_SECONDS`); apagar o `AlertDispatcher`. Conferir que nenhuma referência
  sobrou: `grep -rn AlertDispatcher app tests resources` vazio.
- [ ] **Step 3 (GREEN):** PHPMD com baseline passa (a linha do `AlertDispatcher` já saiu); medir as duas novas e os
  chamadores tocados (`IngestMessage` e `LiveEffects` seguem no baseline: anotar se mudaram).
- [ ] **Step 4:** testes afetados e PHPStan verdes.
- [ ] **Step 5:** commit `refactor: AlertDispatcher dividido em alertas de canal e de ticket`.

### Task 3: `Presence` → `OfflineSchedule`

**Files:**
- Create: `app/Services/Inbox/OfflineSchedule.php`
- Modify: `app/Services/Inbox/Presence.php`, `phpmd.baseline.xml`
- Test: `tests/Feature/Inbox/PresenceSendTest.php`, `PresenceAnnounceTest.php`, `ClientPresenceWindowTest.php`, `MarkTicketSeenTest.php`

**Interfaces:**
- Produces:
  - `OfflineSchedule::schedule(Channel $channel, ?int $delaySeconds = null): void` — token `Str::random(16)` na chave
    `presence-offline:{id}` por 5 min e `GoOfflineJob` com atraso (`OFFLINE_AFTER_SECONDS = 45` mora aqui).
  - `OfflineSchedule::isCurrent(Channel $channel, string $token): bool` — o token gravado é este.
  - `Presence::scheduleOffline(Channel $channel, ?int $delaySeconds = null): void` continua público (API da presença,
    usada por `SendTicketMessage` e pelos fluxos internos) e delega ao `OfflineSchedule`; `goOffline` usa `isCurrent`.
    `openClientWindow` mantém o `max(OFFLINE_AFTER_SECONDS, presence.window_seconds)`, lendo a constante do `OfflineSchedule`.

- [ ] **Step 1 (RED):** apagar a linha do `Presence` no baseline; PHPMD com baseline reprova com 14.
- [ ] **Step 2:** criar o `OfflineSchedule` (injetado no construtor do `Presence`) com o código atual.
- [ ] **Step 3 (GREEN):** PHPMD com baseline passa; `Presence` e `OfflineSchedule` ≤ 13.
- [ ] **Step 4:** testes de presença listados acima, afetados e PHPStan verdes.
- [ ] **Step 5:** commit `refactor: agenda do offline da presença em classe própria`.

### Task 4: `SendTicketMessage` → `OutgoingMessages` + `OutboundStatus`

**Files:**
- Create: `app/Services/Inbox/OutgoingMessages.php`, `app/Services/Inbox/OutboundStatus.php`
- Modify: `app/Services/Inbox/SendTicketMessage.php`, `app/Actions/Inbox/OpenTicketByAgent.php` (Tarefa 1),
  `app/Livewire/Inbox/Concerns/ComposesMessages.php:213`, `app/Services/Bot/Effects/LiveEffects.php:57,82`,
  `app/Services/Inbox/AfterHoursReply.php:52`, `app/Services/Inbox/SendTicketMedia.php:47,52`, os testes que chamam
  `SendTicketMessage::create`/`record` (`AgentSeenTest`, `OutboxDeliveryTest`, `OutboxDrainTest`, `PresenceSendTest`,
  `SendQuotedMessageTest`, `SendTicketMessageTest`, `WaitingSinceTest`), `phpmd.baseline.xml`
- Test: os mesmos, mais `SendMediaTest`, `InboxVoiceTest`, `AfterHoursReplyTest`

**Interfaces:**
- Produces:
  - `OutgoingMessages` (gravação da linha de saída, sem falar com o WAHA): `create(Ticket $ticket, ?User $author, string $text, MessageOrigin $origin = MessageOrigin::PAINEL, ?Message $quoted = null): Message`,
    `record(Ticket $ticket, ?User $author, string $type, string $body, MessageOrigin $origin = MessageOrigin::PAINEL, array $extra = []): Message`,
    `static quoteColumns(?Message $quoted): array<string, mixed>` — corpos e docblock de classe atuais (o parágrafo do
    `create` no clique e do eco `fromMe`).
  - `OutboundStatus` (transições de status da mensagem que sai): `sent(Message $message, Ticket $ticket, array $response): void`
    (o atual `markSent`), `park(Message $message): void`, `fileGone(Message $message): void`,
    `failed(Message $message, Throwable $e): void` (relança como hoje).
  - `SendTicketMessage` fica com `__invoke(Ticket, User, string): Message` (usa `OutgoingMessages::create` e `deliver`) e
    `deliver(Message $message): void` com `transmit`, `replyTo`, `readAttachment`, `waitingAhead` privados.
  - `OpenTicketByAgent` passa a usar `OutgoingMessages::create`.

- [ ] **Step 1 (RED):** apagar a linha do `SendTicketMessage` no baseline; PHPMD com baseline reprova com 20.
- [ ] **Step 2:** criar as duas classes com o código atual; trocar os chamadores de `create`/`record`/`quoteColumns` (app e
  testes) para `OutgoingMessages`; `deliver` não muda de lugar.
- [ ] **Step 3 (GREEN):** PHPMD com baseline passa; medir `SendTicketMessage`, as duas novas, `AfterHoursReply` (estava em
  13), `SendTicketMedia`, `OpenTicketByAgent`; `LiveEffects` segue no baseline (anotar).
- [ ] **Step 4:** testes listados, afetados e PHPStan verdes.
- [ ] **Step 5:** commit `refactor: gravação e status da mensagem de saída fora do SendTicketMessage`.

### Task 5: `IngestMessage` em etapas

**Files:**
- Create em `app/Services/Inbox/`: `PanelEcho.php`, `InboundTicket.php`, `StoreInbound.php`, `InboundRow.php`, `AnnounceInbound.php`
- Modify: `app/Services/Inbox/IngestMessage.php`, `phpmd.baseline.xml`
- Test: `tests/Feature/Inbox/IngestMessageTest.php`, `IngestMultiChannelTest.php`, `CallReplyEchoTest.php`, `MessageChangesTest.php`,
  `ClientPresenceTest.php`, `ClientPresenceWindowTest.php`, `AfterHoursReplyTest.php`, `BroadcastTest.php`, `ListingShapeTest.php`,
  `tests/Feature/Bot/IngestIntegrationTest.php`

**Interfaces:**
- Produces:
  - `PanelEcho::match(Channel $channel, Contact $contact, IncomingMessage $incoming): ?Message` (o `matchPending`, com
    `ECHO_WINDOW_SECONDS = 60` e `BOT_ECHO_WINDOW_SECONDS = 600`) e `PanelEcho::adopt(Message $pending, IncomingMessage $incoming): Message`.
  - `InboundTicket::for(Channel $channel, Contact $contact, IncomingMessage $incoming, IngestMode $mode, CarbonInterface $freshAt, CarbonInterface $arrivedAt): Ticket`
    (o `ticketFor` + `openTicketFor`, com o lock e o `TicketAlerts::queued`) e
    `InboundTicket::hasOpenBefore(Channel $channel, Contact $contact, IncomingMessage $incoming): bool`.
  - `InboundRow::create(Ticket $ticket, Contact $contact, IncomingMessage $incoming): Message` (o `attributes` + `Message::create`).
  - `AnnounceInbound::__invoke(Message $message, Ticket $ticket, IncomingMessage $incoming, IngestMode $mode, CarbonInterface $freshAt, CarbonInterface $arrivedAt): void`
    — o `announce` quando não é importada, e sempre o `ResolveMessageMediaJob` (`afterCommit`) se houver mídia.
  - `StoreInbound::__invoke(Channel $channel, Contact $contact, IncomingMessage $incoming, IngestMode $mode, CarbonInterface $freshAt, CarbonInterface $arrivedAt): Message`
    — o `storeOnce` (transação, `UniqueConstraintViolationException` → a linha do outro job) + `store` + `recordActivity`.
  - `IngestMessage::__invoke(WahaEvent $event): ?Message` igual por fora; fica com `find`, `freshAt`, `queueEnrichment`,
    `LATE_LIVE_SECONDS = 1800` e a decisão do modo. Cortes acima vêm da contagem do plano (IngestMessage 13, StoreInbound 12,
    InboundTicket 12, AnnounceInbound 12); se a medição real pedir, mover um método entre estas cinco classes é livre.

- [ ] **Step 1 (RED):** apagar a linha do `IngestMessage` no baseline; PHPMD com baseline reprova com 27.
- [ ] **Step 2:** criar as cinco classes com o código e os comentários atuais; `IngestMessage` recebe as que usa pelo construtor.
- [ ] **Step 3 (GREEN):** PHPMD com baseline passa; as seis classes ≤ 13.
- [ ] **Step 4:** testes listados, afetados e PHPStan verdes.
- [ ] **Step 5:** commit `refactor: IngestMessage em etapas (eco, ticket, gravação, anúncio)`.
