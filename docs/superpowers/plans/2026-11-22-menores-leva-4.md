# Leva 4 de menores Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fechar sete menores do app num PR só (branch `fix/menores-leva-4`) antes da pré-release `v0.2.0-rc.2`.

**Architecture:** Cada item vai na camada dona da regra: Livewire do canal, serviço de atualização, regra pura de alertas em JS,
views de erro do Laravel, e dois testes. Nenhuma migração, nenhuma dependência nova, nada no compose.

**Tech Stack:** Laravel 13, Livewire 4.4, Flux 2 free, PHPUnit, `node --test`, PostgreSQL 18.

**Spec:** `docs/superpowers/specs/2026-11-22-menores-leva-4-design.md`

## Global Constraints

- Textos de tela em português do Brasil; nenhum texto técnico cru (mensagem de exceção, URL, status HTTP) na tela.
- TDD: RED visto falhando antes do código, em cada tarefa.
- Commits em português com prefixo (`fix:`, `test:`, `chore:`), terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Testes afetados: `git status --porcelain | cut -c4- | codegraph affected --stdin -d 2 --quiet | xargs -r php artisan test`.
- Postgres de teste `pg-test` em pé (receita no CLAUDE.md); não rodar a suíte enquanto um servidor local usa o `fluxie_test`.
- Visual só com tokens e componentes que o redesenho já usa (`bg-canvas`, `bg-surface`, `border-line`, `text-ink`, `text-ink-2/3`, `flux:button`).

## Review Focus

1. `ChannelCard` com exceção que não é do WAHA nem de conexão (ex.: `InvalidArgumentException` "URI must include a scheme and host" por `WAHA_URL` vazio): tela em português, sem o texto em inglês — teste na Tarefa 1.
2. Aviso de versão com tag `v0.2.0` gravada e `to_version` `0.2.0` (ou o contrário): marca como lido mesmo assim — teste na Tarefa 2.
3. Aviso de **outra** versão (mais nova que a instalada agora) continua não lido — teste na Tarefa 2.
4. Página 500 renderiza com o banco fora do ar (não pode consultar `CompanySettings` nem `auth()`) — teste na Tarefa 5.
5. `connection_down` com navegador que não tem `navigator.userActivation` (Firefox antigo): comporta-se como hoje (toca) — teste na Tarefa 6.

---

### Task 1: Erro do WAHA em português no cartão do canal

**Files:**
- Modify: `app/Livewire/Settings/ChannelCard.php:318-321` (`fail`)
- Test: `tests/Feature/Livewire/ChannelsScreenTest.php`

**Interfaces:**
- Produces: `private function fail(Throwable $exception): void` (mesma assinatura; todos os `catch` já chamam).

- [ ] **Step 1: Write the failing tests** em `ChannelsScreenTest`:
  - `test_erro_desconhecido_nao_mostra_o_texto_tecnico`: `Http::fake(fn () => throw new \InvalidArgumentException('URI must include a scheme and host'))`, `call('connect')`; `assertSet('errorMessage', 'Não foi possível falar com o WAHA. Tente de novo; se continuar, avise o administrador.')`.
  - `test_waha_sem_resposta_tem_frase_propria`: `Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: Connection refused'))`; espera `'O WAHA não respondeu. Confira se ele está no ar e tente de novo.'`.
  - `test_waha_recusa_a_chave`: `Http::fake(['*' => Http::response(['message' => 'Unauthorized'], 401)])`; espera `'O WAHA recusou a chave de acesso (WAHA_API_KEY).'`.
  - O `test_waha_fora_do_ar_mostra_erro_e_nao_quebra` existente (500) passa a esperar `'O WAHA respondeu com erro. Tente de novo em instantes.'`.
  - Antes, confirmar com `codegraph_explore WahaClient ensureOk` que 401/500 do WAHA viram `WahaRequestException` com `statusCode`; ajustar o fake se o cliente lançar outra coisa.
- [ ] **Step 2: Run** `php artisan test --filter=ChannelsScreenTest` — FAIL nas quatro (texto antigo concatenado).
- [ ] **Step 3: Implement `fail`** com `match (true)`: `ConnectionException` → sem resposta; `WahaRequestException` 401/403 → chave; `WahaRequestException` ≥ 500 → erro do WAHA; resto → genérica. Sempre `report($exception)` antes. Frases exatamente as dos testes. As frases com "WAHA" mantêm verdes os testes que procuram `str_contains(..., 'WAHA')`.
- [ ] **Step 4: Run** `php artisan test --filter=ChannelsScreenTest` — PASS.
- [ ] **Step 5: Commit** `fix: erro do WAHA em português no cartão do canal`.

### Task 2: Aviso "Versão X disponível" lido quando a atualização para X conclui

**Files:**
- Modify: `app/Services/Update/ApplyUpdate.php:61-64` (`succeed`)
- Test: `tests/Feature/Update/UpdateWatchTest.php`

**Interfaces:**
- Consumes: `App\Notifications\NewVersionAvailable` (payload `['kind' => 'update', 'state' => 'available', 'version' => (string) Version]`), `Version::parse`.
- Produces: nada novo público.

- [ ] **Step 1: Write the failing tests** (sem `Notification::fake` para estas duas: precisam da linha no banco; criar a notificação com `$admin->notify(new NewVersionAvailable('0.2.0'))` antes de `watch()`, ou com `Notification::fake()` desligado no `fakeWorld` — conferir o helper):
  - `test_concluir_marca_como_lido_o_aviso_da_versao_instalada`: admin com aviso `'0.2.0'` e aviso `'0.3.0'`; `at(Reiniciando, 1)`, `to_version` do update `'v0.2.0'`, `config(['fluxie.version' => '0.2.0'])`; depois de `watch()`: aviso `0.2.0` com `read_at` não nulo, aviso `0.3.0` com `read_at` nulo.
  - `test_devolver_nao_marca_o_aviso`: `at(Reiniciando, 11)` com a versão de origem; aviso `0.2.0` segue com `read_at` nulo.
- [ ] **Step 2: Run** `php artisan test --filter=UpdateWatchTest` — FAIL (read_at nulo).
- [ ] **Step 3: Implement** em `succeed`, depois do `close`: `DatabaseNotification::query()->where('type', NewVersionAvailable::class)->whereNull('read_at')->whereRaw("(data::jsonb ->> 'version') = ?", [(string) (Version::parse($update->to_version) ?? $update->to_version)])->update(['read_at' => now()])`. Só quando o `close` de fato fechou: fazer `close` devolver `bool` (o `moveTo` já devolve) e usar só em `succeed`.
- [ ] **Step 4: Run** `php artisan test tests/Feature/Update` — PASS.
- [ ] **Step 5: Commit** `fix: aviso de versão sai do sino quando a atualização conclui`.

### Task 3: `ProductionDependenciesTest` vê nome de classe totalmente qualificado

**Files:**
- Modify: `tests/Unit/ProductionDependenciesTest.php`

**Interfaces:**
- Produces: `private function classesIn(string $code): list<string>` — `use` do topo **e** nomes `\A\B` no corpo.

- [ ] **Step 1: Write the failing test** `test_le_nome_totalmente_qualificado_no_codigo`: `classesIn("<?php\nnamespace App;\nuse Foo\\Bar;\nclass X { function f() { return new \\Laravel\\Pint\\Thing; } }")` contém `'Foo\Bar'` e `'Laravel\Pint\Thing'`; e não contém `'App'` (o `namespace`).
- [ ] **Step 2: Run** `php artisan test --filter=ProductionDependenciesTest` — FAIL (método não existe).
- [ ] **Step 3: Implement** `classesIn` com os dois `preg_match_all`: o atual (`^use ...`) e `/\\\\([A-Z][A-Za-z0-9_]*(?:\\\\[A-Za-z0-9_]+)+)/` (barra inicial + ao menos um separador); o teste principal passa a usar `classesIn`.
- [ ] **Step 4: Run** o filtro — PASS (e o teste principal segue verde: `app/` não usa pacote só de dev).
- [ ] **Step 5: Commit** `test: dependência de produção também por nome totalmente qualificado`.

### Task 4: `SideDatabaseTest` estável no `--parallel`

**Files:**
- Modify: `tests/Feature/Backup/Restore/SideDatabaseTest.php:175-184`
- (Só se o Step 2 mostrar sessão de cliente que não some: `app/Services/Backup/Restore/AdminDatabase.php:50-54` — PARAR e avisar o controlador antes.)

- [ ] **Step 1: Reproduzir (RED).** Temporário, sem commit: antes do `assertSame` do teste, gravar em `storage/logs/side-db.log` as linhas `select pid, backend_type, state, query from pg_stat_activity where datname = ?` quando o total ≠ 0. Rodar com carga até falhar ou 40 voltas: `for i in $(seq 1 40); do php artisan test --parallel --processes=8 tests/Feature/Backup || break; done`. Registrar no relatório o `backend_type` visto (ou "não reproduziu em 40 voltas").
- [ ] **Step 2: Decidir pelo que viu.** `autovacuum worker` ou `client backend` que some em < 2 s → correção no teste (Step 3). `client backend` que não some → parar, relatar.
- [ ] **Step 3: Implement** no teste: (a) `assertArrayNotHasKey('pgsql_side', DB::getConnections())`; (b) contar só `backend_type = 'client backend'` e repetir a contagem a cada 100 ms por até 2 s antes do `assertSame(0, ...)`. Tirar o log temporário.
- [ ] **Step 4: Run** a mesma repetição do Step 1 (40 voltas) — nenhuma falha.
- [ ] **Step 5: Commit** `test: verificação do banco novo sem falha intermitente no paralelo`.

### Task 5: Páginas de erro próprias

**Files:**
- Create: `resources/views/errors/layout.blade.php`, `resources/views/errors/403.blade.php`, `404.blade.php`, `419.blade.php`, `500.blade.php`
- Modify: `resources/views/errors/503.blade.php` (passa a usar o layout; mantém as duas mensagens e a recarga)
- Test: `tests/Feature/ErrorPagesTest.php`

**Interfaces:**
- Produces: `errors.layout` com `@section`/`@yield` `code`, `title`, `message` (e `@yield('head')` para o refresh do 503).

- [ ] **Step 1: Write the failing tests** (`APP_DEBUG` false no teste com `config(['app.debug' => false])`; `$this->withoutVite()`):
  - `test_404_em_portugues_com_volta_ao_inicio`: `get('/nao-existe')` → 404, vê `Página não encontrada` e link para `route('home')` com texto `Voltar ao início`.
  - `test_403`: rota de admin acessada por atendente (usar uma já existente nos testes de autorização) → vê `Acesso negado`.
  - `test_419`: `view('errors.419')->render()` contém `Sessão expirada`.
  - `test_500_renderiza_sem_banco`: `DB::shouldReceive('connection')->andThrow(new RuntimeException)` (ou `config(['database.default' => 'nada'])`) e `view('errors.500')->render()` contém `Algo deu errado` — não pode lançar.
  - `test_503_mantem_as_duas_mensagens`: com e sem restauração em curso (fake de `RestoreProgress::isRunning`) → `Restauração do banco em andamento` / `Sistema em manutenção`, e `http-equiv="refresh"` presente.
- [ ] **Step 2: Run** `php artisan test --filter=ErrorPagesTest` — FAIL (páginas padrão do Laravel, em inglês).
- [ ] **Step 3: Implement.** `errors/layout`: HTML próprio, sem `partials.head` (ele consulta `CompanySettings` e `auth()`); só `meta charset/viewport`, `<title>` com o título, favicons, `@fluxAppearance`, `@vite(['resources/css/app.css'])`. Corpo no arranjo de `layouts/auth/simple.blade.php` (cartão `max-w-[400px] rounded-xl border border-line bg-surface p-7 shadow-xs`, fundo `bg-canvas`), com o nome `config('app.name')` no lugar da marca da empresa, código em `text-ink-3`, título, frase, `flux:button` como `<a href="{{ route('home') }}">` "Voltar ao início" (sem `wire:navigate`). Textos:
  - 403 "Acesso negado" / "Seu usuário não tem permissão para esta página."
  - 404 "Página não encontrada" / "O endereço pode ter mudado ou não existe."
  - 419 "Sessão expirada" / "A página ficou aberta por muito tempo. Volte e tente de novo."
  - 500 "Algo deu errado" / "O erro foi registrado. Tente de novo em instantes."
  - 503: as duas mensagens atuais, sem botão; recarga por `<meta http-equiv="refresh" content="15">` (a CSP bloqueia script inline). Conferir o que o 503 atual usa para recarregar e manter o mesmo intervalo.
- [ ] **Step 4: Run** `php artisan test --filter=ErrorPagesTest` e `npm run build` — PASS.
- [ ] **Step 5: Commit** `feat: páginas de erro próprias em português`.

### Task 6: Som do WhatsApp caído obedece ao som desligado e ao gesto

**Files:**
- Modify: `resources/js/alerts-rules.js:33-34` (`soundFor`), `resources/js/alerts.js:111-117` (`once`)
- Test: `tests/js/alerts-rules.test.mjs`

**Interfaces:**
- Produces: `soundFor(kind, settings, ctx)` aceita `ctx.activated` (`boolean | undefined`); `undefined` = navegador sem a API, conta como ativado.

- [ ] **Step 1: Write the failing tests** em `alerts-rules.test.mjs`:
  - `'o crítico fica calado com todos os sons desligados'`: `sound` com os 4 em `false` → `soundFor('connection_down', s, { now: NOW, activated: true })` é `null`; com só `sla: true` → `'critico'`.
  - `'o crítico só toca depois de um gesto na página'`: `activated: false` → `null`; `activated: true` → `'critico'`; sem `activated` → `'critico'`.
  - O teste existente do silêncio segue igual.
- [ ] **Step 2: Run** `node --test tests/js` — FAIL nos dois novos.
- [ ] **Step 3: Implement** em `soundFor`: `connection_down` devolve `null` se `isMuted`, se `ctx.activated === false` ou se `!Object.values(sound).some(Boolean)`; senão `'critico'`. Em `alerts.js` `once`, passar `activated: navigator.userActivation?.hasBeenActive` no `ctx`. A repetição (`loop`) já relê a regra a cada volta: depois do primeiro clique a próxima volta toca. Faixa vermelha e balão não mudam.
- [ ] **Step 4: Run** `node --test tests/js` e `npm run build` — PASS.
- [ ] **Step 5: Commit** `fix: som do WhatsApp caído respeita o som desligado e espera um gesto`.

### Task 7: Apagar `Version::isRelease()`

**Files:**
- Modify: `app/Services/Update/Version.php:25-28`, `tests/Unit/Update/VersionTest.php:49`

- [ ] **Step 1:** Tirar a linha `assertTrue(...->isRelease())` de `test_prefixo_v` e trocá-la por `assertSame('0.0.0-teste.1', (string) Version::parse('0.0.0-teste.1'))` (pré-release continua sendo versão válida, que era o que a linha queria provar).
- [ ] **Step 2:** Apagar o método; `vendor/bin/phpstan analyse --no-progress` e `php artisan test --filter=VersionTest` — PASS.
- [ ] **Step 3: Commit** `chore: remove Version::isRelease sem uso`.
