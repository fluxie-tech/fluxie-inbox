# Acoplamento — fatia 2 (Bot) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** tirar do `phpmd.baseline.xml` a `LiveEffects` (34) e o `BotEngine` (29), cada um com CBO ≤ 13, sem mudar
comportamento.

**Architecture:** a `LiveEffects` continua implementando `BotEffects` e delega cada assunto a um colaborador que recebe o
mesmo `Ticket`; o `BotEngine` perde o mapa dos blocos (classe própria, suprimida com motivo, decisão do dono) e a criação e
retomada da sessão ao vivo.

**Tech Stack:** Laravel 13, PHP 8.4, PHPMD 3.0.0, PHPStan + PHPat, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-25-refatoracao-acoplamento-design.md`

## Global Constraints

- CBO ≤ 13 em toda classe criada ou tocada que não esteja no baseline. Atenção: `IngestMessage`, `AnnounceInbound`,
  `Presence`, `OpenTicketByAgent` e `AfterHoursReply` estão em 13 exatos; esta fatia não pode acrescentar tipo a elas.
- Proibido baixar o número com trait, apagando tipo (type hint, `@return`/`@throws`/`@var`), `mixed`/`object`, classe por
  string ou classe de passagem (spec, "Antiobjetivos").
- Única supressão permitida nesta fatia: o mapa `NodeType` → bloco, por doc comment
  `@SuppressWarnings("PHPMD.CouplingBetweenObjects")` com o motivo na mesma linha (nunca o atributo do PHPMD).
- Comportamento, textos, constantes e ordem dos efeitos idênticos; testes existentes só mudam na linha que chama algo que
  mudou de lugar. Comentários existentes (o "porquê") viajam com o código movido.
- Commits em português com prefixo `refactor:`, terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Medição (todas as tarefas)

- Com baseline (o que reprova): `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml --baseline-file=phpmd.baseline.xml`
- Valor de uma classe: `rm -rf ~/.cache/pdepend` e depois `vendor/bin/phpmd analyze <arquivo> --format text --ruleset .superpowers/phpmd-cbo.xml`
  (um arquivo por chamada; o cache do PDepend guarda classes apagadas).
- Testes: sempre `DB_PORT=5433 php artisan test <arquivos>`, em primeiro plano, um processo por vez.
- Tipos: `vendor/bin/phpstan analyse --no-progress`.
- RED de cada tarefa: apagar a linha da classe no `phpmd.baseline.xml` e ver o PHPMD com baseline reprovar com o valor atual.
  Classes novas sem teste próprio quando a lógica movida já é exercida pelos testes do chamador.

## Review Focus

1. O `exclusive` refaz o `refresh()` do ticket dentro da transação: os colaboradores usam a **mesma instância** de `Ticket`
   da `LiveEffects`, senão leem estado velho — `LiveEffectsTest`, `EngineCoreTest` (Tarefa 1).
2. `enterQueue` com atribuição: a fila não toca o som quando há pessoa atribuível; se a atribuição falhar, `queued` sai em
   seguida com o ticket recarregado — `LiveEffectsTest` (Tarefa 1).
3. A mídia do bot só aceita caminho dentro do disco `local`, sem `..`; arquivo que sumiu só registra no log — `LiveEffectsTest` (Tarefa 1).
4. A mensagem de encaminhamento do `deliver` usa as variáveis da execução com `setor` trocado pelo setor escolhido —
   `EngineCoreTest` (mensagens `encaminhamento_setor`/`encaminhamento_geral` com `{{nome}}`) (Tarefa 2).
5. `MAX_STEPS = 50` e o "#sair" em qualquer bloco continuam — `EngineCoreTest`, `EngineExtraNodesTest` (Tarefa 2).

---

### Task 1: `LiveEffects` delega por assunto

**Files:**
- Create em `app/Services/Bot/Effects/`: `LiveMessages.php`, `LiveTyping.php`, `LiveQueue.php`, `LiveTags.php`, `LiveRecords.php`
- Modify: `app/Services/Bot/Effects/LiveEffects.php`, `app/Services/Bot/Effects/SimulatedEffects.php:79`, `phpmd.baseline.xml`
- Test: `tests/Feature/Bot/LiveEffectsTest.php`, `EngineCoreTest.php`, `EngineBlocksTest.php`, `EngineExtraNodesTest.php`,
  `BuiltinVarsTest.php`, `IngestIntegrationTest.php`, `WorkflowTemplatesTest.php`

**Interfaces:**
- Consumes: `OutgoingMessages::create/record` e `TicketAlerts::queued/assigned` (fatia 1), `Presence::botTyped`.
- Produces (todas `final`, construtor `__construct(private readonly Ticket $ticket)`, criadas pela `LiveEffects` no próprio
  construtor com o **mesmo** `$ticket`):
  - `LiveMessages`: `say(string $text): void`, `media(string $path, string $caption): void` (com o `copyToMediaDisk` privado).
  - `LiveTyping`: `wait(int $seconds): void`.
  - `LiveQueue`: `enter(?Sector $sector, ?User $assignTo): ?int` (o atual `enterQueue` com `assign` e `queue` privados) e
    `public static function assignable(?User $user, ?Sector $sector): ?User` — a `SimulatedEffects` passa a chamar
    `LiveQueue::assignable`; `LiveEffects::assignable` deixa de existir.
  - `LiveTags`: `tag(Tag $tag): void`, `hasTag(Tag $tag): bool` (com o `tagOwner` privado e seu `@return`).
  - `LiveRecords`: `saveContactField(string $field, string $value): void`, `note(string $body): void`, `close(): void`,
    `logHandoff(string $reason, ?Sector $sector, ?int $assignedUserId): void`.
  - `LiveEffects::__construct(Ticket $ticket)` não muda (o `LiveEffectsTest` e o `BotEngine` fazem `new LiveEffects($ticket)`);
    ela fica com `exclusive`, `save`, `entered`, `isOpen`, `contactName`, `returning`, `protocol` e os métodos da interface
    delegando. O `@SuppressWarnings("PHPMD.TooManyPublicMethods")` com o motivo continua.

- [ ] **Step 1 (RED):** apagar a linha da `LiveEffects` no baseline; PHPMD com baseline reprova com 34.
- [ ] **Step 2:** criar as cinco classes movendo corpos e comentários; a `LiveEffects` cria cada uma no construtor e delega.
- [ ] **Step 3 (GREEN):** PHPMD com baseline passa; medir `LiveEffects`, as cinco novas e `SimulatedEffects` (8 hoje). Esperado: todas ≤ 13.
- [ ] **Step 4:** testes listados e PHPStan verdes.
- [ ] **Step 5:** commit `refactor: LiveEffects delega mensagens, digitando, fila, etiquetas e registros`.

### Task 2: `BotEngine` sem o mapa dos blocos e sem a sessão ao vivo

**Files:**
- Create: `app/Services/Bot/Nodes/NodeFactory.php`, `app/Services/Bot/LiveSessions.php`
- Modify: `app/Services/Bot/BotEngine.php`, `app/Services/Bot/BotRun.php:55-58`, `app/Services/Bot/BotEntry.php:25,81`, `phpmd.baseline.xml`
- Test: `tests/Feature/Bot/EngineCoreTest.php`, `EngineBlocksTest.php`, `EngineExtraNodesTest.php`, `IngestIntegrationTest.php`,
  `WorkflowTemplatesTest.php`, `BuiltinVarsTest.php`, os do simulador (`codegraph affected`)

**Interfaces:**
- Consumes: `LiveEffects` (Tarefa 1, construtor igual).
- Produces:
  - `final class NodeFactory` com `public static function make(NodeType $type): Node` — o `match` atual do `BotEngine::node`,
    um `new` por chamada como hoje. O `BotEngine` chama `NodeFactory::make($type)` direto e perde o `node()` privado
    (o tipo de retorno `Node` contaria de novo). Contagem do plano: `BotEngine` 13 (BotSession, Message, Sector, Ticket, User,
    WorkflowVersion, NodeType, Step, BotRun, ChoiceReply, BotMessages, NodeFactory, LiveSessions). Docblock da classe com
    `@SuppressWarnings("PHPMD.CouplingBetweenObjects") um mapa do tipo do bloco para a classe dele: o acoplamento é a função da classe`.
  - `final class LiveSessions`:
    - `start(BotEngine $engine, Ticket $ticket, WorkflowVersion $version): BotRun` — `FlowGraph::fromArray`, `BotSession::create`
      (mesmos campos), o `TicketEvent` `bot_started` e a execução com `new LiveEffects($ticket)`.
    - `resume(BotEngine $engine, BotSession $session): BotRun` — o atual `liveRun`.
    - `release(Ticket $ticket): void` — o atual `BotEngine::release` (fila geral e `TicketEvent` `bot_handoff` com
      `reason` `sem_workflow`); a `BotEntry` passa a chamá-lo (recebe `LiveSessions` no construtor). `BotEngine::release` sai.
  - `BotEngine::start(Ticket, WorkflowVersion): BotSession` igual por fora (`$run = sessions->start(...)`, `begin($run)`,
    devolve `$run->session`); `receive` e `handoff` usam `resume`. `LiveSessions` entra no construtor do `BotEngine`.
  - `BotRun::say(string $template, array $extra = []): void` com `@param array<string, string> $extra` — renderiza com
    `[...$this->vars(), ...$extra]`; o `deliver` passa a usar `$run->say(BotMessages::text(...), ['setor' => ...])`.

- [ ] **Step 1 (RED):** apagar a linha do `BotEngine` no baseline; PHPMD com baseline reprova com 29.
- [ ] **Step 2:** criar `NodeFactory` e `LiveSessions` com o código atual; ajustar `BotEngine`, `BotRun::say` e `BotEntry`.
- [ ] **Step 3 (GREEN):** PHPMD com baseline passa; medir `BotEngine`, `LiveSessions`, `BotRun`, `BotEntry` (11 hoje) e
  `NodeFactory` (suprimida; anotar o valor). Esperado: todas menos `NodeFactory` ≤ 13.
- [ ] **Step 4:** testes listados, afetados e PHPStan verdes.
- [ ] **Step 5:** commit `refactor: BotEngine sem o mapa dos blocos e sem montar a sessão ao vivo`.
