# Segurança e CI — Plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fechar os itens 9 a 14 das pendências: coletor da CSP, supervisor na Auditoria, `WahaClient` só na camada de serviço,
PHPat, PHPMD moderno, trava de migração no CI e uma medição do Infection.

**Architecture:** Tudo no branch `feat/seguranca-ci` (já tem o commit do vite e a spec). Código de produto com TDD; as
ferramentas de análise entram no `composer ci:static`, que o job obrigatório `static` já roda.

**Tech Stack:** Laravel 13, Livewire 4.4, PHPStan 2.2 + Larastan, PHPat 0.12.5, PHPMD 3.0.0, Node (`node --test`), Infection
0.35 (só em container).

**Spec:** `docs/superpowers/specs/2026-11-25-seguranca-e-ci-design.md`

## Global Constraints

- Commits em português com prefixo (`feat:`, `fix:`, `refactor:`, `ci:`, `docs:`, `test:`), terminando com
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Nenhuma classe nova ou alterada pode passar de 13 em `CouplingBetweenObjects`: o baseline (Task 8) só guarda as 22 que já
  existiam em 25/11.
- Nada de serviço externo no app (coletor da CSP é interno).
- Testes que dependem de expiração no cache simulam com `Cache::forget`, nunca `travel()`.
- Afrouxar regra do PHPMD ou do PHPat só com o dono; exceção sempre nomeada e com motivo.
- Testes afetados: `git status --porcelain | cut -c4- | codegraph affected --stdin -d 2 --quiet | xargs -r php artisan test`.

## Review Focus

1. Relatório da CSP malformado, gigante ou de tipo desconhecido → 204, nada gravado, nada logado (Task 3).
2. Uma origem mandando muitos relatórios: os iguais viram uma linha por hora, e acima do limite por IP a resposta é 429 (Task 3).
3. Relatório chegando com o sistema em manutenção ou antes do primeiro acesso → 204, sem redirecionar nem 503 (Task 3).
4. Supervisor que digita `/auditoria/sistema` → 403; e na Auditoria de tickets ele não vê a barra de abas (Task 1).
5. Migration com `dropColumn` só no `down()`, num comentário ou numa string → passa; marcador `// contract:` sem motivo →
   reprova (Task 9).

---

### Task 1: Supervisor lê a Auditoria de tickets

**Files:**
- Modify: `routes/web.php:53-66` (rota `auditoria` sai do grupo `can:admin` e entra no `can:supervisor`; `auditoria.sistema` fica)
- Modify: `app/Livewire/Audit/Index.php:34-37` (`authorize('supervisor')`)
- Modify: `app/Support/Navigation.php:35` (portão do item "Auditoria" → `'supervisor'`)
- Modify: `resources/views/components/audit-tabs.blade.php` (o `<nav>` inteiro só para quem `@can('admin')`)
- Test: `tests/Feature/Livewire/AuditPageTest.php`

**Interfaces:** nenhuma nova.

- [ ] **Step 1: Reescrever o teste de acesso (RED)**

Trocar `test_so_admin_entra` por:

```php
public function test_supervisor_e_admin_entram_atendente_nao(): void
{
    $this->actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))->get('/auditoria')->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->get('/auditoria')->assertOk();
    $this->actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->get('/auditoria')->assertOk();
}

public function test_supervisor_nao_ve_a_aba_sistema_e_admin_ve(): void
{
    $this->actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->get('/auditoria')
        ->assertDontSee('aria-label="Tipo de auditoria"', false)->assertDontSee(route('auditoria.sistema'));
    $this->actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->get('/auditoria')
        ->assertSee(route('auditoria.sistema'));
}

public function test_supervisor_ve_o_item_auditoria_no_menu(): void
{
    $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);
    $this->assertContains('auditoria', array_map(fn ($item) => $item->route, (new Navigation)->visible($supervisor)));
}
```

- [ ] **Step 2: Rodar e ver falhar** — `php artisan test --filter=AuditPageTest` → os três falham (403 do supervisor; item fora do menu).
- [ ] **Step 3: Implementar** as quatro mudanças da lista de arquivos.
- [ ] **Step 4: Rodar** `php artisan test --filter='AuditPageTest|SystemLogPageTest|NavigationTest'` → PASS (o
  `SystemLogPageTest:39` segue exigindo 403 do supervisor em `/auditoria/sistema`).
- [ ] **Step 5: Commit** `feat: supervisor lê a Auditoria de tickets; a aba Sistema segue só do admin`

---

### Task 2: A política da CSP manda os relatórios ao app

**Files:**
- Modify: `app/Http/Middleware/SecurityHeaders.php:26-60`
- Modify: `docs/security.md` (seções da CSP, do HSTS e do Dependabot)
- Test: `tests/Feature/Security/SecurityHeadersTest.php`

**Interfaces:**
- Produces: a URL `/csp-report` e o nome de endpoint `csp`, que a Task 3 atende.

- [ ] **Step 1: Testes (RED)**

```php
public function test_politica_manda_relatorio_ao_coletor_interno_nos_dois_modos(): void
{
    foreach (['enforce' => 'Content-Security-Policy', 'report-only' => 'Content-Security-Policy-Report-Only'] as $mode => $header) {
        config(['security.csp_mode' => $mode]);
        $response = $this->get('/login');
        $this->assertStringContainsString('report-uri /csp-report', (string) $response->headers->get($header));
        $this->assertStringContainsString('report-to csp', (string) $response->headers->get($header));
        $this->assertSame('csp="/csp-report"', $response->headers->get('Reporting-Endpoints'));
    }
}

public function test_csp_desligada_nao_manda_endpoint_de_relatorio(): void
{
    config(['security.csp_mode' => 'off']);
    $this->assertFalse($this->get('/login')->headers->has('Reporting-Endpoints'));
}
```

- [ ] **Step 2: Rodar** `php artisan test --filter=SecurityHeadersTest` → os dois novos falham.
- [ ] **Step 3: Implementar** — duas diretivas no fim de `policy()` (`'report-uri /csp-report'`, `'report-to csp'`) e o
  cabeçalho `Reporting-Endpoints: csp="/csp-report"` só quando `$header !== null`.
- [ ] **Step 4: Rodar** de novo → PASS (os 14 testes antigos inclusive).
- [ ] **Step 5: Documentar em `docs/security.md`:** (a) o coletor (rota, dedupe de 1 h, onde aparece: Auditoria › Sistema,
  grupo "Segurança"); (b) HSTS `preload` fica desligado, com os dois motivos da spec (item 3); (c) decisão do dono em
  25/11/2026: sem auto-merge do Dependabot; o Dependency graph já está ligado.
- [ ] **Step 6: Commit** `feat: CSP manda os relatórios de violação ao coletor interno`

---

### Task 3: Coletor `POST /csp-report`

**Files:**
- Create: `app/Http/Controllers/CspReportController.php` (invocável)
- Create: `app/Services/Security/CspReports.php`
- Modify: `app/Enums/AuditAction.php` (case `CspViolation = 'csp.violation'`, rótulo `'Violação da CSP'`, grupo `'Segurança'`,
  `groups()` com `'Segurança'` no fim)
- Modify: `app/Support/AuditLogText.php` (frase do case novo)
- Modify: `config/security.php` (`'csp_report_per_minute' => 30`, `'csp_report_max_bytes' => 16384`)
- Modify: `app/Providers/AppServiceProvider.php` (`RateLimiter::for('csp-report', …)` por IP, com o valor da config)
- Modify: `routes/web.php` (rota), `bootstrap/app.php:30-36` (`csp-report` nas exceções do CSRF e da manutenção)
- Test: `tests/Feature/Security/CspReportTest.php`, `tests/Feature/Audit/AuditActionTest.php`

**Interfaces:**
- Produces:
  - `CspReports::parse(string $body, string $contentType): list<array{directive: string, blocked: string, document: string, source: ?string, line: ?int, disposition: string}>`
    — aceita `application/csp-report` (`{"csp-report": {...}}`, campos `effective-directive`/`violated-directive`,
    `blocked-uri`, `document-uri`, `source-file`, `line-number`, `disposition`) e `application/reports+json` (lista; só itens
    `type == "csp-violation"`, campos `effectiveDirective`, `blockedURL`, `documentURL`, `sourceFile`, `lineNumber`,
    `disposition`). Qualquer outro tipo ou JSON inválido → `[]`. No máximo 10 itens. Cada texto cortado em 255; `document`
    sem query string nem fragmento.
  - `CspReports::record(array $violation): void` — `Cache::add('csp-report:'.sha1(directive|blocked|document), true, 3600)`;
    só se entrou: `Auditor::record(AuditAction::CspViolation, null, $violation['directive'], [blocked, document, source, line, disposition], 'Navegador')`
    e `Log::warning('CSP: violação', $violation)`.
  - Frase: `"{$who} bloqueou {blocked} ({subject}) em {document}"`.

- [ ] **Step 1: Testes (RED)** em `CspReportTest` (todos com `security.csp_mode` padrão):
  - `test_relatorio_classico_vira_linha_na_auditoria_do_sistema` — POST com `Content-Type: application/csp-report` e
    `{"csp-report":{"effective-directive":"script-src-elem","blocked-uri":"https://evil.test/x.js","document-uri":"https://app.test/inbox?ticket=9#a","disposition":"enforce"}}`
    → 204; um `AuditLog` com `action = 'csp.violation'`, `subject = 'script-src-elem'`, `actor_user_id = null`,
    `actor_label = 'Navegador'`, `detail['document'] = 'https://app.test/inbox'`.
  - `test_relatorio_novo_reports_json_tambem_e_aceito` — `application/reports+json` com um item `csp-violation` e um
    `deprecation` → uma linha só.
  - `test_violacao_repetida_na_mesma_hora_vira_uma_linha` — dois POST iguais → 1 linha; `Cache::forget` da chave e terceiro
    POST → 2 linhas.
  - `test_corpo_invalido_grande_ou_de_outro_tipo_responde_204_sem_gravar` — JSON quebrado; `text/plain`; corpo de 16385 bytes
    → 204 e zero linhas.
  - `test_nao_abre_sessao_nem_devolve_cookie` — resposta sem `Set-Cookie`.
  - `test_responde_204_em_manutencao_e_antes_do_primeiro_acesso` — com `$this->artisan('down')` e, noutro caso, sem admin
    (estado do `RedirectToSetup`) → 204.
  - `test_acima_do_limite_por_ip_responde_429` — com `config(['security.csp_report_per_minute' => 2])`, terceiro POST → 429.
  - Em `AuditActionTest`: `$this->assertSame('Segurança', AuditAction::CspViolation->group());` e a frase de um `AuditLog`
    de `csp.violation` sem "{" nem "detail" (o teste de "toda ação vira frase" já cobre).
- [ ] **Step 2: Rodar** `php artisan test --filter='CspReportTest|AuditActionTest'` → falham (rota 404, case inexistente).
- [ ] **Step 3: Implementar.** Rota: `Route::post('csp-report', CspReportController::class)->middleware('throttle:csp-report')->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, AddQueuedCookiesToResponse::class, EncryptCookies::class, RedirectToSetup::class])->name('csp-report');`.
  Controller: corpo acima de `csp_report_max_bytes` (pelo `Content-Length` ou `strlen($request->getContent())`) → 204 sem
  ler; senão `parse` + `record` de cada item; sempre `response()->noContent()`.
- [ ] **Step 4: Rodar** de novo → PASS; também `php artisan test --filter='SystemLogPageTest|AuditorTest'`.
- [ ] **Step 5: Commit** `feat: coletor interno de violações da CSP na Auditoria do sistema`

---

### Task 4: `WahaClient` fora do Inbox (presença e mídia)

**Files:**
- Modify: `app/Services/Inbox/Presence.php` (método novo)
- Modify: `app/Livewire/Inbox/Concerns/ComposesMessages.php:250-279` (usa o método novo; sai o `use WahaClient`)
- Modify: `app/Services/Waha/WahaMedia.php` (construtor nomeado)
- Modify: `app/Http/Controllers/MessageMediaController.php:45` (sai o `use WahaClient`)
- Test: o teste de presença existente do Inbox (achar com `codegraph_explore "Presence typing recording test"`), `tests/Feature/Media/*` do controller

**Interfaces:**
- Produces:
  - `Presence::announce(Ticket $ticket, bool $on, bool $recording): void` — o trecho atual de `announcePresence` a partir de
    `$channel = $ticket->channel()->firstOrFail()` (inclui o `isConnected()`, o `recording`/`typing` e o
    `humanActive`/`scheduleOffline`). Autorização e limite continuam no componente.
  - `WahaMedia::forChannel(Channel $channel): self`.

- [ ] **Step 1: Teste (RED)** de `Presence::announce` com `Http::fake` do WAHA: canal conectado + `$on = true, $recording = false`
  chama o endpoint de digitação (o mesmo que o teste de presença existente confere); canal desconectado não chama nada.
  Teste de `WahaMedia::forChannel($channel)` devolvendo instância que usa a sessão do canal (`Http::fake` + `assertSent` na URL
  da sessão).
- [ ] **Step 2: Rodar** o filtro dos dois testes → falham (métodos inexistentes).
- [ ] **Step 3: Implementar** e trocar as chamadas no componente e no controller.
- [ ] **Step 4: Rodar** os testes afetados (comando das Global Constraints) → PASS; `grep -n WahaClient app/Livewire/Inbox app/Http` → nada.
- [ ] **Step 5: Commit** `refactor: Inbox e mídia chegam ao WAHA pelos serviços`

---

### Task 5: `WahaClient` fora de Configurações › Canais

**Files:**
- Create: `app/Services/Waha/ChannelSession.php`
- Modify: `app/Livewire/Settings/ChannelCard.php` (linhas 83, 106, 137, 155, 172, 195, 211, 261, 305)
- Modify: `app/Livewire/Settings/Concerns/SendsTestMessage.php` (envio e `leaveOffline` saem para o serviço)
- Modify: `app/Services/Waha/WahaQr.php` (`WahaQr::forChannel(Channel $channel): self`)
- Test: `tests/Feature/Waha/ChannelSessionTest.php`; os testes existentes do `ChannelCard` (ex.: `ChannelCardCallsTest`)

**Interfaces:**
- Produces: `final class ChannelSession` com `__construct(Channel $channel)` e `start(): void`, `stop(): void`,
  `deleteSession(): void`, `session()` e `requestPairingCode(string $phone)` com os mesmos tipos de retorno do `WahaClient`,
  e `sendTestMessage(string $phone, string $text): ?string` (envia para `$channel->normalizeChatId($phone)`, depois
  `setPresence('offline')` best-effort, devolve o `id` da resposta ou `null`). Exceções do WAHA sobem como hoje (o
  componente continua no `try/catch` + `fail()`).

- [ ] **Step 1: Teste (RED)** `ChannelSessionTest` com `Http::fake`: `start()`, `stop()` e `deleteSession()` batem nos mesmos
  endpoints que o `WahaClient` usa; `sendTestMessage('5511999998888', 'oi')` envia e depois pede presença `offline`, e uma
  falha na presença não lança; devolve o id.
- [ ] **Step 2: Rodar** `php artisan test --filter=ChannelSessionTest` → falha (classe inexistente).
- [ ] **Step 3: Implementar** e trocar as nove chamadas do componente e a do trait; o trait fica só com validação e mensagens.
- [ ] **Step 4: Rodar** os testes afetados → PASS; `grep -rn WahaClient app/Livewire app/Http` → nada.
- [ ] **Step 5: Commit** `refactor: cartão do canal chega ao WAHA pelo ChannelSession`

---

### Task 6: Regras de arquitetura com PHPat

**Files:**
- Modify: `composer.json`/`composer.lock` (`composer.bat require --dev phpat/phpat:^0.12.5`)
- Modify: `phpstan.neon` (include `vendor/phpat/phpat/extension.neon`; `tests/Architecture` em `paths`; serviço com tag `phpat.test`)
- Create: `tests/Architecture/LayerRules.php` (namespace `Tests\Architecture`; nome sem sufixo `Test` para o PHPUnit não pegar)

**Interfaces:**
- Consumes: Tasks 4 e 5 (nenhum `WahaClient` em `App\Livewire` nem `App\Http`).
- Produces: quatro métodos `test_*(): Rule`, cada um com `->because('…')` em português:
  1. `App\Models` não depende de `App\Livewire`, `App\Http`.
  2. `App\Services`, `App\Actions` não dependem de `App\Livewire`, `App\Http`.
  3. `App\Enums`, `App\Support` não dependem de `App\Livewire`, `App\Http`, `App\Services`.
  4. Classes de `App` exceto `App\Services`, `App\Actions`, `App\Jobs`, `App\Console` não dependem de `App\Services\Waha\WahaClient`.

- [ ] **Step 1: Escrever as regras e provar que pegam (RED)** — criar temporariamente `app/Livewire/ArchProbe.php` que
  instancia `WahaClient`; rodar `vendor/bin/phpstan analyse --no-progress` → erro da regra 4 citando `ArchProbe`. Apagar o arquivo.
- [ ] **Step 2: Rodar** `vendor/bin/phpstan analyse --no-progress` → `[OK] No errors`. Se alguma regra 1–3 acusar algo
  (o builder check viu zero), parar e relatar ao controlador, sem criar exceção.
- [ ] **Step 3: Commit** `ci: regras de arquitetura com PHPat no PHPStan`

---

### Task 7: PHPMD 3 e cleancode sem ruído

**Files:**
- Modify: `phpmd.xml` (`maxmethods` → `maximum`; `<rule ref="rulesets/cleancode.xml">` com `<exclude name="StaticAccess"/>` e
  `<exclude name="BooleanArgumentFlag"/>`, cada exclusão com comentário do motivo; comentário dizendo por que `naming.xml`
  fica de fora)
- Modify (os 20 avisos de 25/11):
  - `IfStatementAssignment`: `app/Actions/Sectors/DeleteSector.php:26`, `app/Console/Commands/ResetSystemCommand.php:37`,
    `app/Http/Controllers/MessageMediaController.php:40`, `app/Livewire/Settings/Sectors.php:42` e `:74`,
    `app/Services/Backup/BackupCatalog.php:51`, `app/Services/Inbox/IngestMessage.php:52` e `:65`
  - `ElseExpression`: `app/Livewire/Contacts/Index.php` (`mount`), `app/Livewire/Inbox/NewConversation.php` (`open`),
    `app/Services/Alerts/AlertRecipients.php` (`sla`, `seesQueue`), `app/Services/Bot/Simulation/BotSimulator.php` (`reply`),
    `app/Services/Inbox/ApplyMessageChange.php` (`reaction`), `app/Services/Inbox/BusinessHours.php` (`summary`),
    `app/Services/Inbox/ImportHistory.php` (`chats`), `app/Services/Setup/ApplySegment.php` (`__invoke`)
  - `MissingImport`: `app/Services/Inbox/DrainOutbox.php:84`, `app/Services/Inbox/OutboxRecovery.php:30`
  - `ErrorControlOperator`: `app/Services/Media/AvatarImage.php:36` (trocar o `@` por checagem explícita do retorno, mantendo
    o mesmo resultado para imagem inválida)

**Interfaces:** nenhuma; comportamento idêntico.

- [ ] **Step 1: RED** — mudar só o `phpmd.xml` e rodar `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml` →
  os 20 avisos acima (a lista pode ter mudado com as Tasks 4–5; corrigir o que aparecer).
- [ ] **Step 2: Corrigir** arquivo por arquivo (early return no lugar do `else`; atribuição antes do `if`; `use` no topo).
- [ ] **Step 3: Rodar** o PHPMD → sem avisos; testes afetados → PASS; `vendor/bin/phpstan analyse --no-progress` → OK.
- [ ] **Step 4: Commit** `refactor: PHPMD 3 com cleancode (sem StaticAccess e BooleanArgumentFlag)`

---

### Task 8: Acoplamento (design) com baseline

**Files:**
- Modify: `phpmd.xml` (`<rule ref="rulesets/design.xml/CouplingBetweenObjects"/>`, limite padrão 13)
- Modify: `app/Models/Ticket.php`, `app/Models/Message.php` (`#[SuppressWarnings(CouplingBetweenObjects::class)]` de
  `PHPMD\Attribute\SuppressWarnings`, com comentário de uma linha: o acoplamento vem dos tipos do Eloquent)
- Create: `phpmd.baseline.xml` (gerado)
- Modify: `composer.json` (`complexity` com `--baseline-file=phpmd.baseline.xml`), `CLAUDE.md` (comando do PHPMD no portão 7)

- [ ] **Step 1: Medir** `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml` → a lista de classes acima de 13.
  Conferir que são só as 24 de 25/11 (ou menos). **Qualquer classe nova ou criada nas Tasks 3–5 na lista: parar e reduzir o
  acoplamento dela; nunca entra no baseline.**
- [ ] **Step 2: Suprimir** nos dois models e rodar de novo → 22 classes; o `UnusedSuppression` não acusa.
- [ ] **Step 3: Gerar** `vendor/bin/phpmd analyze app --ruleset phpmd.xml --generate-baseline` e conferir que o arquivo tem
  22 entradas de `CouplingBetweenObjects` e nada mais.
- [ ] **Step 4: Provar que o baseline segura classe nova** — criar temporariamente uma classe em `app/` com 14 dependências,
  rodar `composer.bat complexity` → falha citando ela; apagar e rodar → passa.
- [ ] **Step 5: Commit** `ci: PHPMD reprova acoplamento acima de 13 (baseline temporário das 22 classes atuais)`

---

### Task 9: Trava de migração destrutiva

**Files:**
- Create: `.github/scripts/migration-guard.mjs`
- Create: `tests/js/migration-guard.test.mjs` (fixtures em `tests/js/fixtures/migrations/` se precisar)
- Modify: `.github/workflows/tests.yml` (job `static`, passo depois de "Run static checks")
- Modify: `docs/deploy.md` (seção curta sobre expand/contract e o marcador `// contract: <motivo>`)

**Interfaces:**
- Produces: `export function unmarkedDestructive(source: string): Array<{ line: number, op: string }>` — localiza o corpo de
  `function up(` por contagem de chaves (ignorando chaves em strings e comentários), procura `dropColumn`, `dropColumns`,
  `renameColumn`, `->change()`, `Schema::drop(`, `Schema::dropIfExists(`, `Schema::rename(` fora de comentários e strings,
  e aceita a ocorrência se a mesma linha ou a linha anterior tiver `// contract: ` seguido de texto não vazio. CLI: recebe os
  caminhos como argumentos, imprime `arquivo:linha op` de cada achado e sai 1 se houver algum.

- [ ] **Step 1: Testes (RED)** em `node:test`:
  - `dropColumn no up sem marcador reprova` → `[{ line: N, op: 'dropColumn' }]`
  - `marcador na linha anterior libera` / `marcador na mesma linha libera`
  - `marcador sem motivo não libera` (`// contract:` só)
  - `dropColumn só no down passa` → `[]`
  - `palavra em comentário ou string não conta` → `[]`
  - `->change() e Schema::rename reprovam`
  - `dropForeign e dropIndex passam`
  - `migration sem up() devolve vazio`
- [ ] **Step 2: Rodar** `node --test tests/js/migration-guard.test.mjs` → falha (módulo inexistente).
- [ ] **Step 3: Implementar** o módulo e o bloco CLI no padrão do `npm-audit.mjs` (`process.argv[1] === fileURLToPath(import.meta.url)`).
- [ ] **Step 4: Passo no CI** — no job `static`:

```yaml
      # Só migration nova do PR: apagar, renomear ou mudar tipo de coluna quebra a versão anterior durante o rollback.
      - name: Check destructive migrations
        if: github.event_name == 'pull_request' && steps.tested.outputs.tested != 'true'
        run: |
          files=$(git diff --name-only --diff-filter=A HEAD^1 HEAD -- database/migrations)
          [ -z "$files" ] || node .github/scripts/migration-guard.mjs $files
```

- [ ] **Step 5: Rodar** `node --test tests/js` → PASS; `node .github/scripts/migration-guard.mjs database/migrations/*.php`
  → lista os destrutivos antigos (esperado: só para conferir que roda; os antigos não passam pelo CI).
- [ ] **Step 6: Documentar** a convenção e commitar `ci: trava de migração destrutiva sem marcador contract`

---

### Task 10: Medição do Infection (controlador, sem implementador)

**Files:**
- Create: `docs/superpowers/reports/2026-11-25-infection.md`

- [ ] **Step 1:** Postgres de teste no ar (`docker ps`; senão o comando do `CLAUDE.md`).
- [ ] **Step 2:** Container descartável PHP 8.5 com `pcov` (mesma família da imagem do CI), montando o projeto em `/app`,
  `DB_HOST=host.docker.internal`, instalando o Infection lá dentro (`composer require --dev infection/infection` numa cópia
  ou o PHAR), sem alterar `composer.json` nem `composer.lock` do repositório.
- [ ] **Step 3:** Rodar sobre `app/Services`, `app/Actions`, `app/Policies` (`--filter` por diretório ou `source.directories`
  num `infection.json5` temporário fora do repositório). `--threads` só se cada thread receber banco próprio; senão em série.
- [ ] **Step 4:** Relatório: MSI, Covered MSI, mutantes mortos/sobreviventes/sem cobertura, tempo, comando exato, e os 10
  sobreviventes mais relevantes (arquivo:linha, mutador). Sem limite proposto para o CI (decisão do dono depois).
- [ ] **Step 5:** `git status` sem nada além do relatório; commit `docs: medição do Infection (25/11/2026)`.

---

## Depois das tarefas (controlador)

- Portão 6: `revisor-final` no branch inteiro.
- Portão 7: `vendor/bin/pint --parallel --test --cache-file=.pint.cache`, `vendor/bin/phpstan analyse --no-progress`,
  `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml --baseline-file=phpmd.baseline.xml`, `node --test tests/js`,
  `npm run build`, suíte com Redis.
- Conferência visual (Task 1): `conferente-visual` em 720p, 1080p e 2K como supervisor e admin na Auditoria.
- Memória: tirar os itens 9 a 14 de `pendencias-v1` e registrar em `historico-entregas`.
