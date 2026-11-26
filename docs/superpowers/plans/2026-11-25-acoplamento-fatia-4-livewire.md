# Acoplamento — fatia 4 (Livewire) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** tirar do `phpmd.baseline.xml` as 11 telas restantes, levar a trait `ComposesMessages` a ≤ 13 medida como classe e
apagar o baseline.

**Architecture:** consultas e regras saem da tela para classes de consulta (`App\Services\...`) e de ação (`App\Actions\...`);
a tela fica com estado, autorização, validação de formulário, mensagens de erro na view, eventos e `redirect`. Sem subcomponentes.

**Tech Stack:** Laravel 13, Livewire 4.4, Flux 2, PHP 8.4, PHPMD 3.0.0, PHPStan + PHPat, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-25-refatoracao-acoplamento-design.md`

**Pesquisa (detalhe do corte de cada tela, só leitura):** `.superpowers/sdd/fatia-4-pesquisa/inbox.md`, `settings.md`, `outras.md`.
Cada tarefa lê a seção da sua tela. A pesquisa propõe nomes, assinaturas e riscos; o que está nesta página prevalece.

## Global Constraints

- CBO ≤ 13 em toda classe criada ou tocada. Já em 13 exatos (não acrescentar tipo): `IngestMessage`, `AnnounceInbound`,
  `Presence`, `OpenTicketByAgent`, `AfterHoursReply`, `LiveEffects`, `RestoreDatabase`, `PrepareRestore`, `Cutover`,
  `RestoreReport`, `ResetSystem`.
- **Extrair só até chegar a 13.** A pesquisa lista as extrações em ordem de peso; cada tarefa extrai na ordem e para quando a
  tela medir ≤ 13, desde que cada classe nova tenha uma responsabilidade inteira (nada de classe de um método só para tirar um
  tipo). Traits que medem ≤ 13 (`ActsOnMessages`, `TakesNotes`, `RecoversHistory`, `SendsTestMessage`, `ReceivesAvatar`) não se mexem.
- O PHPMD conta tipos de parâmetro, retorno e propriedade, `new`, chamada estática (facades), `instanceof`, `catch`, `X::class`,
  `@param`/`@return`/`@var`/`@throws` simples e atributos de método **e de propriedade** (`#[Url]`, `#[Locked]`); não conta
  atributo de classe, classe pai, genérico de docblock nem código de trait.
- Proibido baixar o número com trait, apagando tipo (type hint, `@return`/`@throws`/`@var`), `mixed`/`object`, classe por string
  (`app('...')`, nome montado) ou classe de passagem (spec, "Antiobjetivos"). Serviço nunca em propriedade pública do componente
  (o Livewire tentaria hidratar): injeção no método (`public function save(SaveUser $save)`) ou no construtor da classe nova.
- Camadas (PHPat): classe nova não recebe tipo de `App\Livewire` nem `App\Http` (o `ContactForm` fica na tela).
- Supressão `@SuppressWarnings("PHPMD.CouplingBetweenObjects")` com motivo só se, depois de extrair toda regra e consulta, a tela
  passar de 13 apenas por tipos do framework. A pesquisa não prevê nenhuma.
- Contrato público igual: propriedades públicas, métodos chamados pela Blade/JS, eventos `#[On]`/`dispatch`, chaves passadas à
  view. HTML não muda. Antes de mexer em método ou propriedade pública, buscar o nome em `resources/views` e `resources/js`.
- Autorização (`authorize`, `abort_if`, `Auth::user() ?? abort(403)`), confirmações (palavra, senha, caixa), `reset('resetPassword')`
  e a ordem "checa → autoriza → valida → age → limpa o painel" ficam visíveis na tela.
- Textos, constantes, chaves de cache e de rate limit (`send-message:`, `presence:`, `message-action:`, `reset-system:`,
  `restore-dispatch`, `reset-system-dispatch`, `undo-note:`) e a ordem dos efeitos idênticos. Testes existentes só mudam na linha
  que chama algo que mudou de lugar. Comentários existentes (o "porquê") viajam com o código.
- Commits em português com prefixo `refactor:`, terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Medição (todas as tarefas)

- Com baseline (o que reprova): `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml --baseline-file=phpmd.baseline.xml`
- Valor de uma classe: `rm -rf ~/.cache/pdepend` e `vendor/bin/phpmd analyze <arquivo> --format text --ruleset .superpowers/phpmd-cbo.xml`
  (um arquivo por chamada). Trait medida como classe: copiar para fora do repositório trocando `trait X` por `class X` e medir a cópia.
- Testes: `DB_PORT=5433 php artisan test <arquivos>`, em primeiro plano, um processo por vez, nada em segundo plano.
- Tipos: `vendor/bin/phpstan analyse --no-progress`.
- RED de cada tarefa: apagar a linha da tela no `phpmd.baseline.xml` e ver o PHPMD com baseline reprovar com o valor atual (a trait
  não está no baseline: o RED dela é a cópia medida em 15). Classes novas sem teste próprio quando a lógica movida já é exercida
  pelos testes da tela; caminho sem teste ganha teste de caracterização antes de mover (a pesquisa aponta: `mount` com `?ticket=` e
  o aviso de handoff da `Inbox`).

## Review Focus

1. Inbox: a lista é calculada uma vez por render (a presença e o setor da lista reaproveitam o mesmo resultado); `close()` checa
   "já encerrado" antes de autorizar e validar; `transfer`/`reassign` autorizam, validam, agem e fecham o painel nessa ordem (Tarefa 1).
2. Compositor: a citação vale só para a primeira mensagem; texto vira legenda do primeiro arquivo, menos áudio ou acima de 1024;
   limite de 4096 conta a assinatura; na falha geral a bandeja fica, nas falhas por arquivo os temporários são apagados (Tarefa 2).
3. Nota apagada: o token de desfazer é por usuário e só é gasto depois de todas as conferências (Tarefa 3).
4. Restauração e zerar pela tela: o latch é tomado, o bloqueio é conferido de novo dentro dele, a auditoria sai antes do job, e o
   `begin` do andamento antes do `dispatch`; senha nunca fica no snapshot do Livewire (Tarefas 5 e 6).
5. Card do canal: as ações mudam a mesma instância hidratada de `$this->channel`; 404 na consulta da sessão vira STOPPED sem zerar
   o QR (Tarefa 7).

---

### Task 1: `Inbox\Inbox` (30)

**Files:** Create `app/Services/Inbox/TicketQueueQuery.php`, `TicketLookup.php`, `TicketPanel.php`, `app/Actions/Inbox/OpenTicketForAgent.php`;
modify `app/Livewire/Inbox/Inbox.php`, `app/Services/Inbox/ResendTicketMessage.php` (ganha `forTicket`), `phpmd.baseline.xml`.
Test: `tests/Feature/Livewire/Inbox*`, `tests/Feature/Inbox/{ClaimAndCloseTest,CloseWithSummaryTest,QueueSearchTest,QueueSummaryTest,AgentSeenTest,MarkTicketSeenTest,OutboxResendTest}`, `tests/Feature/History/InboxClosedTabTest.php`, `tests/Feature/Sla/InboxSlaTest.php` e afetados.

**Interfaces (Produces):** as de `inbox.md` §1 — `TicketQueueQuery::snapshot(User, string $tab, ?int $tagId, string $search, ?Ticket): array`
(chaves do render atual); `TicketLookup` com `canSee`, `selected`, `detailed`, `readable`, `visibleOrFail`, `previous`,
`closedAmong`, `handoff` (usados também nas Tarefas 2 e 3); `TicketPanel` (regras e mensagens dos três painéis, `transfer`,
`reassign`, `close`, `sectors`, `candidates`, `alreadyClosed`); `OpenTicketForAgent::__invoke(Ticket, User): void`;
`ResendTicketMessage::forTicket(Ticket, int $messageId, User, bool $confirmed): string`.

- [ ] **Step 1:** teste de caracterização do `mount` com `?ticket=` e aviso/rascunho de handoff, se não existir; ver passar no código atual.
- [ ] **Step 2 (RED):** apagar a linha da `Inbox` no baseline; PHPMD reprova com 30.
- [ ] **Step 3:** extrair na ordem da pesquisa até a tela medir ≤ 13.
- [ ] **Step 4 (GREEN):** PHPMD passa; medir a tela e cada classe nova (todas ≤ 13).
- [ ] **Step 5:** testes listados, afetados e PHPStan verdes.
- [ ] **Step 6:** commit `refactor: Inbox sem consultas e regras da fila, do painel e da busca do ticket`.

### Task 2: trait `ComposesMessages` (15 como classe)

**Files:** Create `app/Services/Inbox/QuickReplyLookup.php`, `AgentRateLimits.php`, `app/Actions/Inbox/SendComposedMessage.php`,
`SendVoiceMessage.php`, `AnnounceAgentPresence.php` (na ordem, só os necessários); modify `app/Livewire/Inbox/Concerns/ComposesMessages.php`.
Test: `InboxAttachmentsTest`, `InboxReplyTest`, `InboxComposerModeTest`, `InboxDraftCleanupTest`, `InboxQuickRepliesButtonTest`,
`QuickRepliesTest`, `InboxTypingTest`, `PresenceSendTest`, `InboxVoiceTest`, `Inbox/SendMediaTest`, `SignatureTest`, `SaveNoteTest`, `Security/RateLimitTest`.

**Interfaces:** Consumes `TicketLookup` (Tarefa 1). Produces as de `inbox.md` §2; `SendComposedMessage::MESSAGE_LIMIT` público
(a trait usa em `validateDraft`). A trait mantém `private function reserveSends(int $count): bool` (o `TakesNotes::saveNote` chama).

- [ ] **Step 1 (RED):** medir a cópia da trait como classe: 15.
- [ ] **Step 2:** extrair na ordem da pesquisa até a cópia medir ≤ 13.
- [ ] **Step 3 (GREEN):** cópia ≤ 13; classes novas ≤ 13; PHPMD com baseline passa; a `Inbox` continua ≤ 13.
- [ ] **Step 4:** testes listados e PHPStan verdes.
- [ ] **Step 5:** commit `refactor: compositor do Inbox com envio, voz e limites em classes próprias`.

### Task 3: `Inbox\Transcript` (21)

**Files:** Create `app/Services/Inbox/TicketNoteTrash.php`, `TranscriptQuery.php`, `app/Actions/Inbox/ContactFromCard.php`;
modify `app/Livewire/Inbox/Transcript.php`, `phpmd.baseline.xml`. Test: `InboxTranscriptTest`, `InboxNotesTranscriptTest`,
`Inbox/TranscriptOrderTest`, `Inbox/SaveNoteTest`, `ContactCardMessageTest`, `BubbleColorsTest`, `RealtimeRefreshTest`,
`Inbox/AgentSeenTest`, `MarkTicketSeenTest`, `MessageChangesTest`.

**Interfaces:** Consumes `TicketLookup::readable` (Tarefa 1). Produces as de `inbox.md` §3 (`TicketNoteTrash::find/delete/restore`
com `UNDO_SECONDS` e a chave `undo-note:{userId}:{token}`; `TranscriptQuery::for(User, int $ticketId): array{messages, notes}`;
`ContactFromCard::__invoke(int $ticketId, int $messageId, int $index): ?Contact`).

- [ ] Steps: RED (21) → extrair → GREEN (≤ 13, PHPMD passa) → testes e PHPStan → commit `refactor: Transcript sem a lixeira de notas, a consulta e o contato do cartão`.

### Task 4: `Inbox\NewConversation` (15)

**Files:** Create `app/Actions/Inbox/ResolveTypedContact.php`, `app/Services/Inbox/NewConversationChoices.php`; modify
`app/Livewire/Inbox/NewConversation.php`, `app/Services/Inbox/ConversationHandoff.php` (ganha `flashExisting`), `phpmd.baseline.xml`.
Test: `NewConversationModalTest`, `Inbox/StartConversationTest`, `Contacts/ChatButtonsTest`, `ContactsDrawerTest`,
`ContactTagsEverywhereTest`, `History/HistoryPageTest`, `ContactCardMessageTest`.

**Interfaces:** as de `inbox.md` §4. O `submit` continua validando antes de buscar canal e setor; `ConversationNotStarted`
continua chegando ao `catch` da tela.

- [ ] Steps: RED (15) → extrair → GREEN → testes e PHPStan → commit `refactor: NewConversation sem a escolha de canal e setor e o contato digitado`.

### Task 5: `Settings\Backups` (26)

**Files:** Create as de `settings.md` §Backups (`RestoreGate`, `RunManualBackup`, `DispatchRestore`, `BackupReview`,
`BackupOverview`, na ordem e só as necessárias); modify `app/Livewire/Settings/Backups.php`, `phpmd.baseline.xml`.
Test: `Livewire/BackupsPageTest`, `BackupsRestoreTest`, `tests/Feature/Restore/**`, `tests/Feature/Backup/**`.

**Interfaces:** as de `settings.md`; `BackupReview::fromUpload` recebe `UploadedFile` (não `mixed`). APIs da fatia 3 só são chamadas.

- [ ] Steps: RED (26) → extrair → GREEN → testes e PHPStan → commit `refactor: Backups sem as guardas, o despacho da restauração e a leitura dos backups`.

### Task 6: `Settings\System` (19)

**Files:** Create as de `settings.md` §System (`UpdateGate`, `RequestUpdate`, `UpdateOverview`, `RequestReset`, na ordem e só as
necessárias); modify `app/Livewire/Settings/System.php`, `phpmd.baseline.xml`. Test: `Livewire/SystemPageTest`,
`ResetSystemPageTest`, `tests/Feature/Update/**`, `tests/Feature/Reset/**`.

**Interfaces:** as de `settings.md`; ordem do pedido de atualização: bloqueio → palavra → ocupado → `SystemUpdate::start` →
auditoria → job; do zerar: caixa → nome → limite (5 tentativas, `reset-system:{id}`, `clear` só no acerto, `hit(60)`) → senha →
latch → problema de novo → `ResetSystem::start`.

- [ ] Steps: RED (19) → extrair → GREEN → testes e PHPStan → commit `refactor: System sem o pedido de atualização e de zerar`.

### Task 7: `Settings\ChannelCard` (16)

**Files:** Create `app/Services/Waha/ChannelLink.php` (e `app/Actions/Channels/DeleteChannel.php` só se ainda passar de 13);
modify `app/Livewire/Settings/ChannelCard.php`, `phpmd.baseline.xml`. Test: `ChannelCardCallsTest`, `ChannelsScreenTest`,
`InactiveChannelTest`, `RecoverMessagesCardTest`, `ImportNowCardTest`, `AccessibleNamesTest`, `Audit/SettingsAuditTest`,
`Components/StatusBadgeSettingsTest`.

**Interfaces:** as de `settings.md` §ChannelCard. Sem `authorize` novo (o acesso vem da rota `can:admin` das páginas que hospedam o card).

- [ ] Steps: RED (16) → extrair → GREEN → testes e PHPStan → commit `refactor: ChannelCard sem as operações da sessão do WAHA`.

### Task 8: `Settings\Users` (14)

**Files:** Create `app/Actions/Users/SaveUser.php` (e `ToggleUserActive`/`UserDirectory` só se ainda passar de 13); modify
`app/Livewire/Settings/Users.php`, `phpmd.baseline.xml`. Test: `UsersPageTest`, `UserAvatarTest`, `Audit/UserAuditTest`, `Components/StatusBadgeSettingsTest`.

**Interfaces:** as de `settings.md` §Users (`getDirty`/papel antigo lidos antes do `save`; senha nunca auditada).

- [ ] Steps: RED (14) → extrair → GREEN → testes e PHPStan → commit `refactor: Users sem a gravação do usuário`.

### Task 9: `Automation\Editor` (16)

**Files:** Create `app/Services/Bot/FlowGraphShape.php`, `EditorConfig.php`, `EditorSimulation.php` (na ordem, só as necessárias);
modify `app/Livewire/Automation/Editor.php`, `phpmd.baseline.xml`. Test: `AutomationEditorTest`, `AutomationSimulatorTest`.

**Interfaces:** as de `outras.md` §Editor; conferir as chamadas `$wire.*` em `resources/js/flow-editor`. Manter o
`@phpstan-import-type StateShape from SimulationState`.

- [ ] Steps: RED (16) → extrair → GREEN → testes e PHPStan → commit `refactor: Editor sem a forma do grafo, a configuração e o simulador`.

### Task 10: `Contacts\ContactCard` (15)

**Files:** Create `app/Actions/Contacts/ResyncContact.php`, `ContactTags.php`, `app/Services/Contacts/ContactCardData.php` (na ordem,
só as necessárias); modify `app/Livewire/Contacts/ContactCard.php`, `phpmd.baseline.xml`. Test: `ContactCard*Test`,
`ContactPhotoViewTest`, `Contacts/ChatButtonsTest`, `RealtimeRefreshTest`, `ContactTagsEverywhereTest`, `StatusBadgeScreensTest`,
`Google/GoogleSyncTriggersTest`, `GoogleConnectionTest`.

**Interfaces:** as de `outras.md` §ContactCard; `ContactCard::MAX_TAGS` continua existindo; sem `app()` para chamar a ação (injeção no método).

- [ ] Steps: RED (15) → extrair → GREEN → testes e PHPStan → commit `refactor: ContactCard sem as etiquetas, a sincronização e os dados do cartão`.

### Task 11: `Setup\FirstSteps` (14)

**Files:** Create `app/Actions/Setup/CreateSetupChannel.php`; modify `app/Livewire/Setup/FirstSteps.php`, `app/Services/Setup/SetupState.php`
(ganha `companyName`, `channelId` e a constante `CHANNEL_KEY`), `phpmd.baseline.xml`. Test: `Setup/FirstStepsTest`, `Livewire/CompanyLogoTest`.

**Interfaces:** as de `outras.md` §FirstSteps; a marca do canal grava na mesma transação; buscar os chamadores do `SetupState` antes.

- [ ] Steps: RED (14) → extrair → GREEN → testes e PHPStan → commit `refactor: FirstSteps sem a criação do canal do assistente`.

### Task 12: `History\Index` (14)

**Files:** Create `app/Services/Inbox/HistoryTicketView.php`, `HistoryFilterOptions.php` (na ordem, só as necessárias; se a
`TranscriptQuery` da Tarefa 3 servir para as mensagens, reutilizar em vez de repetir); modify `app/Livewire/History/Index.php`,
`app/Services/Inbox/HistorySearch.php` (ganha `defaultFrom`), `phpmd.baseline.xml`. Test: `History/*`, `Domain/SectorSoftDeleteTest`,
`Inbox/TranscriptOrderTest`, `Components/EmptyStateScreensTest`, `StatusBadgeScreensTest`, `AccessibleNamesTest`, `TicketDetailsBlocksTest`.

**Interfaces:** as de `outras.md` §History; o ticket aberto vem de `Ticket::inHistoryOf($user)`, nunca de `find` puro.

- [ ] Steps: RED (14) → extrair → GREEN → testes e PHPStan → commit `refactor: Histórico sem a leitura do ticket e as opções de filtro`.

### Task 13: fim do baseline

**Files:** Delete `phpmd.baseline.xml`; modify `composer.json:95` (script `complexity` sem `--baseline-file=phpmd.baseline.xml`),
`CLAUDE.md:107` (comando do portão 7 sem o `--baseline-file`), `phpmd.xml:28` (comentário da regra sem a menção ao baseline).

- [ ] **Step 1:** conferir que o baseline está vazio (só `<phpmd-baseline>`); se sobrou alguma linha, parar e relatar.
- [ ] **Step 2:** apagar o arquivo e as três menções.
- [ ] **Step 3:** `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml` sai com 0; `composer.bat complexity` também.
- [ ] **Step 4:** commit `chore: PHPMD sem baseline (acoplamento de todas as classes em até 13)`.
