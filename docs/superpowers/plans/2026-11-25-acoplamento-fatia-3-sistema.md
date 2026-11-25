# Acoplamento — fatia 3 (Sistema) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** tirar do `phpmd.baseline.xml` a `RestoreDatabase` (33), a `ResetSystem` (22), a `Preflight` (15) e a `ApplyUpdate` (17),
cada uma com CBO ≤ 13, sem mudar comportamento.

**Architecture:** cada classe vira orquestradora das etapas e perde as etapas inteiras para classes novas no mesmo namespace.
A API pública que telas, comandos, jobs e testes (inclusive mocks) usam fica igual.

**Tech Stack:** Laravel 13, PHP 8.4, PHPMD 3.0.0, PHPStan + PHPat, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-25-refatoracao-acoplamento-design.md`

## Global Constraints

- CBO ≤ 13 em toda classe criada ou tocada que não esteja no baseline. Já em 13 exatos (não acrescentar tipo): `IngestMessage`,
  `AnnounceInbound`, `Presence`, `OpenTicketByAgent`, `AfterHoursReply`, `LiveEffects`.
- Proibido baixar o número com trait, apagando tipo (type hint, `@return`/`@throws`/`@var`), `mixed`/`object`, classe por
  string ou classe de passagem (spec, "Antiobjetivos"). Método público de uma linha que delega é aceito só quando a classe
  mantém responsabilidade própria e o método é API usada por telas, comandos ou mocks de teste.
- Sem supressão de CBO nesta fatia.
- Comportamento, textos, constantes, nomes de lock e de chave de cache, ordem dos passos e dos efeitos idênticos; testes
  existentes só mudam na linha que chama algo que mudou de lugar (mocks de `ResetSystem` continuam valendo sem mudança).
  Comentários existentes (o "porquê") viajam com o código movido.
- Commits em português com prefixo `refactor:`, terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Medição (todas as tarefas)

- Com baseline (o que reprova): `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml --baseline-file=phpmd.baseline.xml`
- Valor de uma classe: `rm -rf ~/.cache/pdepend` e depois `vendor/bin/phpmd analyze <arquivo> --format text --ruleset .superpowers/phpmd-cbo.xml`
  (um arquivo por chamada).
- Testes: sempre `DB_PORT=5433 php artisan test <arquivos>`, em primeiro plano, um processo por vez (os testes de restauração e
  de zerar criam bancos `fluxie_test_*`: duas execuções juntas colidem).
- Tipos: `vendor/bin/phpstan analyse --no-progress`.
- RED de cada tarefa: apagar a linha da classe no `phpmd.baseline.xml` e ver o PHPMD com baseline reprovar com o valor atual.
  Classes novas sem teste próprio quando a lógica movida já é exercida pelos testes do chamador.

## Review Focus

1. Restauração: falha antes da troca só apaga o banco novo; falha na troca ou no `migrate` desfaz a troca; falha depois do
   `resume` vira aviso e não desfaz; o sistema nunca fica em manutenção, salvo quando o desfazer falha (`keepDown`) — testes de
   `tests/Feature/Backup` e `BackupsRestoreTest` (Tarefa 1).
2. A mensagem pública da restauração nunca traz a saída do `pg_dump`/`pg_restore` (`DumpFailed` → texto fixo, detalhe no log) (Tarefa 1).
3. Zerar: os dois locks (`restore-database` e `backup-database`) são tomados juntos e soltos no `finally`; o flush do cache
   preserva o estado do andamento; o `--continue` só vale em manutenção — `ResetSystemTest`, `ResetSystemCommandTest` (Tarefa 2).
4. Pré-voo: para no primeiro erro de conexão; a prova de `deploy` vem antes da escrita de `FLUXIE_VERSION` — testes de
   `tests/Feature/Update` (Tarefa 3).
5. Atualização: depois da primeira escrita no Coolify, qualquer falha devolve; reinício sem resposta clara fica em `reiniciando`;
   linha já fechada por outro processo não ganha segunda auditoria (Tarefa 4).

---

### Task 1: `RestoreDatabase` em etapas

**Files:**
- Create em `app/Services/Backup/Restore/`: `PrepareRestore.php`, `Cutover.php`, `RestoreReport.php`
- Modify: `RestoreDatabase.php`, `RestoreProgress.php` (ganha `step`), `RestoreRun.php` (ganha `bestEffort`), `phpmd.baseline.xml`
- Test: `tests/Feature/Backup/**`, `tests/Feature/Livewire/BackupsRestoreTest.php` e afetados (`codegraph affected`)

**Interfaces:**
- Produces:
  - `RestoreProgress::step(string $key, callable $work): void` — o `step` privado atual (running → work → done).
  - `RestoreRun::bestEffort(string $warning, callable $work): void` — o `bestEffort` atual (`report($e)` e o aviso em `warnings`).
  - `PrepareRestore::restore(RestoreRun $run): void` (nomes com `freeArchiveName`/`assertNames` e os passos `inspect`, `safety`,
    `restore`, `verify`) e `PrepareRestore::switchBack(RestoreRun $run): void` (banco guardado e nomes). Sem o `cutover` e sem
    o `begin`/`assertReady`, que ficam na orquestradora.
  - `Cutover::__invoke(RestoreRun $run): void` (`assertWithinDeadline` e os passos `swap`, `migrate`, `resume`, `recover`, com
    `closeOpenUpdates` e `recoverMessages` privados) e `Cutover::undo(RestoreRun $run, string $message): string`.
  - `RestoreReport::succeeded(RestoreRun $run): string` (grava o registro e avisa os admins em melhor esforço, monta e devolve a
    mensagem final), `RestoreReport::publicMessage(Throwable $e): string`, `RestoreReport::log(RestoreRun $run, string $result, string $message): void`.
  - `RestoreDatabase::__invoke(RestoreRequest $request): void` igual por fora, com `RESTORE_STEPS` e `SWITCH_STEPS` nela; fica
    com o lock, `begin`, `assertReady`, a escolha do modo, `finish`/`fail` do andamento, o desfazer via `Cutover::undo` com o
    `keepDown` e o `release`. Contagem do plano: `RestoreDatabase` 12, `PrepareRestore` 12, `Cutover` 11, `RestoreReport` 13.

- [ ] **Step 1 (RED):** apagar a linha da `RestoreDatabase` no baseline; PHPMD com baseline reprova com 33.
- [ ] **Step 2:** criar as três classes e os dois métodos novos com o código atual; a orquestradora recebe as novas pelo construtor
  (conferir que ninguém faz `new RestoreDatabase`).
- [ ] **Step 3 (GREEN):** PHPMD com baseline passa; medir as quatro classes, `RestoreProgress` e `RestoreRun`. Esperado: todas ≤ 13.
- [ ] **Step 4:** testes listados e PHPStan verdes.
- [ ] **Step 5:** commit `refactor: RestoreDatabase em etapas (preparo, troca e relatório)`.

### Task 2: `ResetSystem` sem WAHA, arquivos e guardas

**Files:**
- Create em `app/Services/Reset/`: `WahaSessions.php`, `ResetFiles.php`, `ResetGuard.php`
- Modify: `ResetSystem.php`, `phpmd.baseline.xml`
- Test: `tests/Feature/Reset/**`, `tests/Feature/Console/ResetSystemCommandTest.php`, `tests/Feature/Livewire/ResetSystemPageTest.php`

**Interfaces:**
- Produces:
  - `WahaSessions`: `public const SESSIONS = 'reset:waha-sessions'`; `current(): array` (`@return list<string>`, o `channelSessions`),
    `problem(): ?string` (o `wahaProblem`), `toDisconnect(): array` (`@return list<string>`, com `storedSessions`),
    `disconnect(array $sessions): void` (`@param list<string>`), `waha()` privado.
  - `ResetFiles`: `local(): int` (o corpo atual de `wipeLocalFiles`), `media(): int` (disco de mídia), `backups(): int` (disco
    `backup.disk`), `empty(Filesystem)` privado.
  - `ResetGuard`: `public const LOCK = 'restore-database'`, `COMMAND = 'php artisan app:reset-system --continue'` (público) e os
    privados `BACKUP_LOCK`, `LOCK_SECONDS = 900`, `BACKUP_BUSY`; `problem(bool $continuing = false): ?string` (o `match` atual,
    com `backupRunning` e `WahaSessions::problem`), `acquire(): array` (`@return list<Lock>`, o `lock` + `refuse` atuais, mesmo
    texto e mesmo `progress->fail`), `release(array $locks): void`.
  - `ResetSystem` mantém a API pública (`problem`, `start`, `canContinue`, `wipeLocalFiles`, `run`) e as constantes públicas
    (`STEPS`, `STARTED`; `SESSIONS = WahaSessions::SESSIONS`; `LOCK = ResetGuard::LOCK`), para que comando, tela, job e testes não
    mudem. `problem` e `wipeLocalFiles` delegam; `run` usa `ResetGuard::acquire/release`, `WahaSessions` e `ResetFiles`;
    `failureMessage` usa `ResetGuard::COMMAND`. O `ManualBackupState` sai do construtor dela e vai para o `ResetGuard`.
    Contagem do plano: `ResetSystem` 13, `ResetGuard` 8, `WahaSessions` 6, `ResetFiles` 4.

- [ ] **Step 1 (RED):** apagar a linha da `ResetSystem` no baseline; PHPMD com baseline reprova com 22.
- [ ] **Step 2:** criar as três classes com o código atual e ajustar a `ResetSystem`.
- [ ] **Step 3 (GREEN):** PHPMD com baseline passa; as quatro classes ≤ 13.
- [ ] **Step 4:** testes listados e PHPStan verdes.
- [ ] **Step 5:** commit `refactor: ResetSystem sem WAHA, arquivos e guardas`.

### Task 3: `Preflight` sem as chamadas ao GHCR e ao GitHub

**Files:**
- Create: `app/Services/Update/ReleaseRegistry.php`
- Modify: `app/Services/Update/Preflight.php`, `phpmd.baseline.xml`
- Test: `tests/Feature/Update/**`

**Interfaces:**
- Produces:
  - `ReleaseRegistry::download(string $url): ?string` (o `download` + `body` atuais, mesmo user agent e timeout) e
    `ReleaseRegistry::imageError(Version $target): ?string` (o `imageError` atual com `MANIFEST_ACCEPT`).
  - `Preflight` mantém a API pública (`connection`, `check`, `releaseCompose`, `composeEdited`, `busyErrors`) e recebe o
    `ReleaseRegistry` pelo construtor. Contagem do plano: `Preflight` 12.

- [ ] **Step 1 (RED):** apagar a linha da `Preflight` no baseline; PHPMD com baseline reprova com 15.
- [ ] **Step 2:** criar a classe com o código atual e ajustar a `Preflight`.
- [ ] **Step 3 (GREEN):** PHPMD com baseline passa; as duas ≤ 13.
- [ ] **Step 4:** testes listados e PHPStan verdes (os `Http::fake` dos testes continuam valendo).
- [ ] **Step 5:** commit `refactor: chamadas ao GHCR e ao GitHub fora do Preflight`.

### Task 4: `ApplyUpdate` sem o backup e sem o fechamento

**Files:**
- Create em `app/Services/Update/`: `UpdateBackup.php`, `CloseUpdate.php`
- Modify: `ApplyUpdate.php`, `phpmd.baseline.xml`
- Test: `tests/Feature/Update/**` e afetados

**Interfaces:**
- Consumes: `Preflight` (Tarefa 3, API igual).
- Produces:
  - `UpdateBackup::__invoke(): string` — o `backup` atual (`BackupKind::Manual`, mesmas mensagens; `@throws RuntimeException`).
  - `CloseUpdate::__invoke(SystemUpdate $update, UpdateStatus $status, string $message, AuditAction $action): bool` — o `close`
    atual (sem segunda auditoria) — e `CloseUpdate::dismissNotice(SystemUpdate $update): void` — a marcação como lida do aviso
    "Versão X disponível" que hoje fica no `succeed`.
  - `ApplyUpdate` mantém `__invoke`, `fail`, `succeed` e `revert` públicos e a ordem dos passos. Contagem do plano: `ApplyUpdate` 12,
    `CloseUpdate` 8, `UpdateBackup` 4.

- [ ] **Step 1 (RED):** apagar a linha da `ApplyUpdate` no baseline; PHPMD com baseline reprova com 17.
- [ ] **Step 2:** criar as duas classes com o código atual e ajustar a `ApplyUpdate`.
- [ ] **Step 3 (GREEN):** PHPMD com baseline passa; as três ≤ 13.
- [ ] **Step 4:** testes listados, afetados e PHPStan verdes.
- [ ] **Step 5:** commit `refactor: backup e fechamento da atualização em classes próprias`.
