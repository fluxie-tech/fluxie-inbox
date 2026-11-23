# Zerar sistema Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Botão "Zerar sistema" em Configurações > Sistema que apaga banco, bancos guardados, mídias, backups, filas, cache e
sessões do WAHA dos canais, e devolve a instalação ao `/instalar`.

**Architecture:** Um serviço `ResetSystem` com passos idempotentes roda num job da fila `history` com o sistema em manutenção,
publicando o andamento no mesmo `RestoreProgress` da restauração (com um campo `kind`), mostrado pela página pública
`/restauracao`. A tela chama o serviço pelo Livewire `System`; se um passo falha, o comando `app:reset-system --continue`
termina pelo terminal do Coolify.

**Tech Stack:** Laravel 13, Livewire 4.4, Flux 2 (free), PostgreSQL 18, Redis 8, WAHA (HTTP).

**Spec:** `docs/superpowers/specs/2026-11-23-zerar-sistema-design.md`

## Global Constraints

- Variável `FLUXIE_ALLOW_RESET`, no compose em `x-app-env` como `FLUXIE_ALLOW_RESET: ${FLUXIE_ALLOW_RESET:-false}`, lida só por
  `config('fluxie.allow_reset')` (bool). Desligada: nem tela, nem ação, nem comando zeram.
- Só admin (`can:admin`); a ação Livewire chamada direto confere tudo de novo no servidor.
- Exclusão mútua com restauração, atualização e backup manual: `RestoreProgress::isRunning()`, `SystemUpdate::open()`,
  `ManualBackupState::isRunning()`, e o lock `restore-database` (o mesmo da restauração).
- WAHA: só as sessões dos canais do banco (`channels.waha_session`), nunca "todas as sessões do WAHA".
- Textos em português; título do andamento "Zerando o sistema"; mensagem final
  "Sistema zerado. Abra /instalar e use o código da aba Environment do Coolify."
- Commits em português com prefixo; um commit por tarefa.
- Testes nunca rodam `migrate:fresh` de verdade nem tocam o Redis real: `Maintenance` e `AdminDatabase` são mockados, discos com
  `Storage::fake`, WAHA com `Http::fake`, espera com `Sleep::fake()`.

## Review Focus

1. Dois cliques ou dois admins ao mesmo tempo → só um job é despachado (lock de despacho + `isRunning`).
2. A ação `resetSystem` chamada direto (sem a caixa, com nome errado, senha errada, variável `false`, usuário não admin) → recusa
   sem despachar nada; senha errada repetida → limitada.
3. Continuar depois de falha no meio (`reset-schema` já rodou, canais sumiram do banco) → o `reset-waha` usa a lista guardada e
   as sessões já apagadas (404) não falham.
4. Nome da empresa com maiúsculas e espaços nas pontas → aceito; empresa sem nome → palavra `ZERAR`.
5. WAHA fora do ar antes de começar → o modal mostra o motivo e nada começa.

---

### Task 1: Andamento com tipo (`kind`) e página `/restauracao` para o zerar

**Files:**
- Modify: `app/Services/Backup/Restore/RestoreProgress.php`
- Modify: `resources/views/restore/progress.blade.php`
- Modify: `resources/js/restore-progress.js`
- Modify: `app/Livewire/Settings/Backups.php` (`runNow()` e `blocker()`: mensagem pelo `kind`)
- Test: `tests/Feature/Livewire/BackupsPageTest.php`, `tests/Feature/Backup/Restore/RestoreProgressTest.php`, teste da página (o que já cobre `/restauracao`, ou
  `tests/Feature/Backup/Restore/RestorePageTest.php` novo)

**Interfaces:**
- Produces:
  - `RestoreProgress::begin(string $title, array $keys, string $kind = 'restore'): void`
  - `RestoreProgress::get()` devolve também `'kind' => 'restore'|'reset'` (estado sem `kind` ou inválido → `'restore'`;
    `idle` → `'restore'`)
  - `RestoreProgress::replace(array $state): void` regrava um estado lido por `get()` (com TTL de terminado se o status não for
    `running`)
  - Rótulos novos em `RestoreProgress::STEPS`: `reset-down` "Entrando em manutenção", `reset-waha` "Desconectando o WhatsApp",
    `reset-queues` "Esvaziando as filas", `reset-media` "Apagando as mídias", `reset-backups` "Apagando os backups",
    `reset-databases` "Apagando bancos guardados", `reset-schema` "Recriando o banco", `reset-cache` "Limpando o cache",
    `reset-up` "Voltando ao ar"

- [ ] **Step 1: Testes que falham**
  - `test_begin_guarda_o_kind_e_get_devolve` (`begin('Zerando o sistema', ['reset-down'], 'reset')` → `get()['kind'] === 'reset'`,
    rótulo `Entrando em manutenção`)
  - `test_estado_sem_kind_vale_restore`
  - `test_replace_regrava_o_estado` (begin, `$s = get()`, `Cache::flush()`, `replace($s)` → `get() == $s`)
  - Página: com estado `kind=reset` e `status=failed`, o HTML traz `php artisan app:reset-system --continue` e o link de volta
    aponta para `route('instalar')`; com `kind=reset` e `done`, a meta `restore-done-url` é `route('instalar')`; com
    `kind=restore`, segue `configuracoes.backups` e o texto atual.
  - Backups (`tests/Feature/Livewire/BackupsPageTest.php`): `test_backup_manual_recusado_durante_o_zerar` — com
    `begin('Zerando o sistema', [...], 'reset')`, `runNow()` não enfileira e `problem` é "Há um zerar do sistema em andamento;
    espere ele terminar para fazer um backup."; o `blocker()` da restauração devolve "Há um zerar do sistema em andamento."
    (com `kind=restore`, as mensagens atuais seguem iguais).
- [ ] **Step 2:** rodar `php artisan test --filter=RestoreProgress` e o teste da página → FAIL.
- [ ] **Step 3:** implementar. Na view, o `<title>`, o título `idle`, o link de volta e a meta de fim escolhem pelo `kind`; com
  `kind=reset` e `failed`, um parágrafo extra: "Para continuar, rode no terminal do app no Coolify:
  `php artisan app:reset-system --continue`". No JS, o texto do link e o redirecionamento não mudam de lógica (usam a meta), mas a
  dica de comando aparece/some com o `state.kind === 'reset' && state.status === 'failed'` (elemento `[data-reset-hint]`).
- [ ] **Step 4:** testes → PASS; `node --test tests/js` segue verde.
- [ ] **Step 5:** commit `feat: andamento com tipo e página de andamento do zerar`.

### Task 2: `Maintenance` ganha `fresh()` e `clearQueues()`

**Files:**
- Modify: `app/Services/Backup/Restore/Maintenance.php`
- Test: `tests/Feature/Backup/Restore/MaintenanceTest.php`

**Interfaces:**
- Produces:
  - `Maintenance::fresh(): void` → `Artisan::call('migrate:fresh', ['--force' => true])`; código ≠ 0 lança
    `RestoreRejected('Não foi possível recriar o banco.')`. Sem seed.
  - `Maintenance::clearQueues(): void` → `queue:clear --force` em cada par: conexão `redis` com `outbound`, `inbound`,
    `realtime`, `default`, `google`, `media`; conexão `redis_history` com `history` (as filas do `docker/worker.sh`).
    Constante pública `Maintenance::QUEUES` (`array<string, list<string>>`, conexão → filas).

- [ ] **Step 1: Testes que falham** com `Artisan` espiado (`Artisan::partialMock()` ou `shouldReceive('call')`):
  `test_fresh_chama_migrate_fresh_com_force`, `test_fresh_que_falha_lanca_restore_rejected`,
  `test_clear_queues_limpa_todas_as_filas_do_worker` (sete chamadas, uma por fila, com `--force`).
- [ ] **Step 2:** `php artisan test --filter=MaintenanceTest` → FAIL.
- [ ] **Step 3:** implementar.
- [ ] **Step 4:** PASS.
- [ ] **Step 5:** commit `feat: manutenção recria o banco e esvazia as filas`.

### Task 3: Serviço `ResetSystem` e `ResetSystemJob`

**Files:**
- Create: `app/Services/Reset/ResetSystem.php`, `app/Jobs/ResetSystemJob.php`
- Modify: `config/fluxie.php` (`'allow_reset' => (bool) env('FLUXIE_ALLOW_RESET', false)`), `docker-compose.yml` (linha em
  `x-app-env`, logo abaixo de `FLUXIE_VERSION`), `.env.example` (`FLUXIE_ALLOW_RESET=false`) se ele lista as `FLUXIE_*`
- Test: `tests/Feature/Reset/ResetSystemTest.php`, `tests/Feature/Reset/ResetSystemJobTest.php`

**Interfaces:**
- Consumes: Task 1 (`begin(..., 'reset')`, `replace`, `get`), Task 2 (`fresh`, `clearQueues`), `Maintenance::enter/leave/
  restartWorkers/problem`, `AdminDatabase::stored/staging/drop`, `WahaClient::logout/deleteSession/session`,
  `Message::mediaDisk()`, `Storage::disk(config('backup.disk'))`.
- Produces (`App\Services\Reset\ResetSystem`):
  - `public const STEPS` = as nove chaves da Task 1, na ordem da spec
  - `public const STARTED = 'reset:started'` (cache, `forever`): presente do começo até o `reset-cache`; é o que libera o
    `--continue`
  - `public const SESSIONS = 'reset:waha-sessions'` (cache, `forever`, `list<string>`)
  - `public const LOCK = 'restore-database'`
  - `problem(bool $continuing = false): ?string` — na ordem: variável desligada ("O zerar está desligado nesta instalação.");
    `Maintenance::problem()` (com `$continuing`, ignora só o "já está em manutenção"); `RestoreProgress::isRunning()` (sem
    `$continuing`); `SystemUpdate::open()`; `ManualBackupState::isRunning()`; WAHA: para cada canal com `waha_session`,
    `session()` com `ConnectionException` → "O WAHA não respondeu. Confira se ele está no ar antes de zerar." (404 passa)
  - `start(string $actor): void` — grava `SESSIONS` (sessões dos canais) e `STARTED`, `begin('Zerando o sistema', STEPS,
    'reset')`, `Log::warning` com `$actor` e a contagem de canais, despacha `ResetSystemJob`
  - `run(): void` — pega o lock `LOCK` (1800 s; ocupado → `RestoreRejected`), roda os passos com
    `running`/`done` do progresso; no fim `finish(...)` com a mensagem da spec e `Log::warning` com o resultado. Exceção num
    passo → `progress->fail('<mensagem>. Para continuar: php artisan app:reset-system --continue')`, **fica em manutenção**,
    solta o lock e relança.
  - `canContinue(): bool` → `Cache::has(STARTED)`
- Passos (cada um idempotente):
  - `reset-down`: `enter()` só se `! app()->isDownForMaintenance()`
  - `reset-waha`: para cada nome em `SESSIONS`, `WahaClient` sobre um `Channel` em memória com `waha_session` = nome:
    `logout()` depois `deleteSession()` (ambos aceitam 404)
  - `reset-queues`: `clearQueues()` e `Sleep::for(30)->seconds()`
  - `reset-media` / `reset-backups`: apagar tudo do disco: `deleteDirectory` de cada diretório de topo e `delete` dos arquivos
    da raiz (`directories('')`, `files('')`), o que no S3 apaga em lotes
  - `reset-databases`: `drop()` em cada `stored()` e `staging()`
  - `reset-schema`: `fresh()`
  - `reset-cache`: `$s = progress->get()`, `Cache::flush()`, `progress->replace($s)` (o `STARTED` e o `SESSIONS` vão embora aqui)
  - `reset-up`: `restartWorkers()` e `leave()`
- Produces (`App\Jobs\ResetSystemJob`): fila `history`, `timeout = 840`, `tries = 1`, `failOnTimeout = true`;
  `handle(ResetSystem)` → `run()` (`RestoreRejected` do lock ocupado só loga); `failed(?Throwable)` → `progress->fail(...)` com
  o comando de continuar.

- [ ] **Step 1: Testes que falham** (`ResetSystemTest`, com `Storage::fake('backups')` e o disco de mídia, `Http::fake` do WAHA,
  `Sleep::fake()`, `Maintenance` e `AdminDatabase` mockados, `config(['fluxie.allow_reset' => true])`):
  - `test_run_apaga_tudo_na_ordem` — dois canais; arquivos nos dois discos (raiz e subpastas); ao fim os discos estão vazios, o
    WAHA recebeu `POST .../logout` e `DELETE /api/sessions/<nome>` de cada sessão, `drop` foi chamado para cada guardado,
    `fresh`, `clearQueues`, `restartWorkers` e `leave` uma vez, `Sleep` 30 s, `get()['status'] === 'done'`, `kind === 'reset'`,
    e `Cache::has(ResetSystem::STARTED)` é `false`
  - `test_sessao_ja_apagada_no_waha_nao_falha` (404 no logout e no delete)
  - `test_falha_num_passo_marca_falhou_fica_em_manutencao_e_mostra_o_comando` (`fresh` lança; `leave` nunca chamado; mensagem
    contém `app:reset-system --continue`; `canContinue()` verdadeiro)
  - `test_continuar_usa_as_sessoes_guardadas_mesmo_sem_canais_no_banco` (Review Focus 3)
  - `test_problem_*`: variável desligada; restauração rodando; atualização aberta; backup manual rodando; WAHA fora do ar
    (`Http::fake` lançando `ConnectionException`) (Review Focus 5); tudo pronto → `null`
  - `test_start_guarda_sessoes_marca_inicio_e_despacha_o_job` (`Queue::fake`, `assertPushedOn('history', ResetSystemJob::class)`)
  - `ResetSystemJobTest`: `test_failed_marca_o_andamento_como_falho_com_o_comando`
- [ ] **Step 2:** `php artisan test tests/Feature/Reset` → FAIL.
- [ ] **Step 3:** implementar o serviço, o job, a config e a linha do compose.
- [ ] **Step 4:** PASS; `bash docker/smoke/compose-smoke.sh` não precisa rodar, mas `docker compose config -q` (se o Docker
  estiver de pé) confere a sintaxe do compose.
- [ ] **Step 5:** commit `feat: serviço e job do zerar sistema`.

### Task 4: Comando `app:reset-system --continue`

**Files:**
- Create: `app/Console/Commands/ResetSystemCommand.php`
- Test: `tests/Feature/Console/ResetSystemCommandTest.php`

**Interfaces:**
- Consumes: `ResetSystem::problem(continuing: true)`, `canContinue()`, `run()`.
- Produces: assinatura `app:reset-system {--continue}`.

- [ ] **Step 1: Testes que falham:**
  - `test_sem_continue_recusa_e_aponta_o_botao` (exit 1, texto "Configurações > Sistema")
  - `test_variavel_desligada_recusa` (exit 1, mesmo com `STARTED` no cache)
  - `test_sem_zerar_comecado_recusa` (`STARTED` ausente → exit 1, "Nenhum zerar para continuar")
  - `test_continua_e_termina` (`ResetSystem` mockado: `run` chamado uma vez, exit 0)
  - `test_falha_no_run_sai_com_1_e_mostra_a_mensagem`
- [ ] **Step 2:** FAIL.
- [ ] **Step 3:** implementar; roda `run()` direto no processo (sem fila).
- [ ] **Step 4:** PASS.
- [ ] **Step 5:** commit `feat: comando para continuar um zerar que falhou`.

### Task 5: Seção "Zerar sistema" em Configurações > Sistema

**Files:**
- Modify: `app/Livewire/Settings/System.php`, `resources/views/livewire/settings/system.blade.php`
- Test: `tests/Feature/Livewire/ResetSystemPageTest.php`

**Interfaces:**
- Consumes: `ResetSystem::problem()`, `start(string $actor)`, `CompanySettings::name()`.
- Produces (no `System`): `public bool $resetAgree = false`, `public string $resetName = ''`, `public string $resetPassword = ''`,
  `public ?string $resetProblem = null`; `askReset()` (abre o modal e preenche `resetProblem`), `cancelReset()`, `resetSystem()`.
  Palavra esperada: `CompanySettings::name()` sem espaços nas pontas; vazia → `ZERAR`; comparação com `mb_strtolower(trim())`.

- [ ] **Step 1: Testes que falham** (`ResetSystem` mockado onde o teste não é sobre ele):
  - `test_desligado_mostra_o_texto_sem_botao` (vê "Desligado nesta instalação" e `FLUXIE_ALLOW_RESET=true`; não vê o botão)
  - `test_ligado_mostra_o_botao_e_o_modal_com_os_avisos` (vê "inclusive TODOS os backups", "gere um backup e baixe-o antes" e
    o link para `route('configuracoes.backups')`)
  - `test_zera_com_caixa_nome_e_senha_certos` (`start` chamado com "Nome <email>" e redireciona para `route('restauracao')`)
  - `test_nome_com_maiusculas_e_espacos_e_aceito` e `test_empresa_sem_nome_pede_ZERAR` (Review Focus 4)
  - `test_recusa_sem_caixa`, `test_recusa_nome_errado`, `test_recusa_senha_errada`,
    `test_senha_errada_repetida_e_limitada` (6ª tentativa em 1 min recusada com aviso de espera, sem conferir a senha)
    (Review Focus 2)
  - `test_recusa_com_variavel_desligada_mesmo_chamando_a_acao_direto` e `test_supervisor_recebe_403`
  - `test_problema_aparece_e_nada_comeca` (`problem` devolve texto → aparece no modal, `start` nunca chamado)
  - `test_dois_cliques_despacham_um_so` (Review Focus 1: o segundo `resetSystem()` encontra o lock de despacho
    `reset-system-dispatch` ou o `isRunning` e não chama `start` de novo)
- [ ] **Step 2:** FAIL.
- [ ] **Step 3:** implementar. Ação: `authorize('admin')`; lock de despacho `Cache::lock('reset-system-dispatch', 120)`;
  `RateLimiter` por usuário (`reset-system:<id>`, 5 por minuto) antes do `Hash::check`; `problem()`; `start()`; redireciona para
  `restauracao`. Blade: seção no fim da página com `flux:heading`, botão `variant="danger"` e `flux:modal` com
  `flux:checkbox`, `flux:input` (nome) e `flux:input type="password"`; o botão final fica `:disabled` sem a caixa ou sem o nome
  conferido (o servidor confere de novo).
- [ ] **Step 4:** PASS; rodar os testes afetados (comando do CLAUDE.md) e o `SystemPageTest`.
- [ ] **Step 5:** commit `feat: seção Zerar sistema em Configurações > Sistema`.

### Task 6: Documentação

**Files:**
- Modify: `docs/deploy.md` (variável `FLUXIE_ALLOW_RESET`: o que faz, padrão `false`, ligar só em staging; e o comando
  `php artisan app:reset-system --continue` para quando um zerar falhar)
- Modify: `README.md` só se ele lista as variáveis do Coolify

- [ ] **Step 1:** escrever.
- [ ] **Step 2:** commit `docs: variável e comando do zerar sistema`.
