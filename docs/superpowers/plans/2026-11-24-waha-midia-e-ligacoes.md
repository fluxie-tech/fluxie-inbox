# WAHA: prazo da mídia, chats ignorados e recusa de ligações — Plano

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O WAHA apaga a mídia com 14 dias e deixa de receber grupos, status, canais e listas de transmissão; o app recusa
ligações em todos os canais pelo app `calls` do WAHA, com chave e texto editáveis em Configurações > Canais.

**Architecture:** O compose leva o que é fixo (prazo da mídia, regras de ignorar, liberação do app `calls`). O app guarda a
chave e o texto em `settings` (`CallSettings`) e os aplica por sessão pela API de apps do WAHA (`WahaCalls`), sem reiniciar
sessão: ao salvar a tela, ao conectar um canal e numa conferência diária. O `app:purge-media` passa a expirar a mídia sem cópia
local com mais de 14 dias, e o `IngestMessage` ignora o eco da resposta automática de ligação.

**Tech Stack:** Laravel 13, Livewire 4.4, Flux 2, PostgreSQL 18, WAHA `devlikeapro/waha:gows-2026.10.1` (Core, GOWS).

**Spec:** `docs/superpowers/specs/2026-11-24-waha-midia-e-ligacoes-design.md`

**Resultado do portão (Task 1, 24/11, staging):** a varredura da partida apagou o arquivo de 20 dias; ligação recusada e
mensagem recebida; `group` sem `message` aceito (201). **Mas `POST` e `PUT /api/apps` reiniciam a sessão** (status
`STARTING`), e o WAHA aceita criar app para sessão inexistente (`GET` 200, `POST` 201). O eco da resposta automática chega
como `fromMe` com `source: "api"` e abre ticket "em atendimento" (Task 6 vale). Decisões (dono pediu ajuda; Claude decidiu):
- Só escrever no WAHA quando o app difere do desejado (comparar `enabled` e `config` do `GET`); igual = nenhuma chamada.
  Reinício só ao salvar uma mudança real e ao conectar. A tela avisa que salvar reinicia a conexão por alguns segundos.
- `applyAll()` só para canais `active` com status `WORKING`; os demais recebem ao conectar. Sem caso de 404.
- Task 6 casa por `source === 'api'` **e** texto em `knownMessages()`.

**Desvio da spec:** a spec diz "no `WahaClient::start()`"; a aplicação ao conectar fica no `ChannelCard`, logo depois de cada
`start()` (únicos chamadores), para o `WahaClient` não depender de `WahaCalls`, que o usa. O efeito é o mesmo.

## Global Constraints

- Prazo da mídia no WAHA: `WHATSAPP_FILES_LIFETIME: "1209600"` (14 dias); no app, a constante espelho vale `14`.
- Nunca chamar `PUT /api/sessions/{sessão}` para aplicar a configuração de ligações (reinicia a sessão).
- Nunca usar os endpoints de apagar mensagem do WAHA para limpeza.
- Toda chamada ao WAHA passa pelo `WahaClient` (`http()` e `check()`), com a chave e a URL do ambiente.
- Id do app de ligações no WAHA: `app_` + nome da sessão. Corpo: `app`, `session`, `id`, `config.dm` e `config.group`, cada um
  com `reject` e `message`; `group` vai sem `message`.
- Texto padrão da mensagem: `Este número não recebe ligações. Envie sua mensagem em texto ou áudio que responderemos por aqui.`
- Chave padrão: recusar ligado (`true`).
- Textos de tela, comentários e commits em português; commits com prefixo (`feat:`, `fix:`, `docs:`).
- Testes precisam do Postgres de teste no ar (ver CLAUDE.md, "Postgres de teste"); sem ele o teste trava.

## Review Focus

- WAHA fora do ar ao salvar a seção Ligações: a configuração fica salva e a tela avisa que será aplicada depois (Task 4).
- Canal ativo fora de `WORKING` na conferência diária: não recebe chamada (o WAHA criaria app órfão); e app já igual não
  é reescrito, para não reiniciar a sessão todo dia (Task 3).
- Texto vazio, só espaços ou com caractere de controle com a chave ligada: recusado pela validação (Task 4).
- Chave desligada: o app `calls` é removido do WAHA e o eco deixa de ser ignorado (Tasks 3 e 6).
- Mensagem `fromMe` com texto diferente do configurado: continua entrando como hoje (Task 6).

---

### Task 1: Portão manual no staging (controlador com o dono, não implementador)

Nada de código. O controlador conduz, o dono executa o que é no Coolify e no celular. Resultado vai ao ledger.

- [ ] **Step 1:** Com o OK do dono, acrescentar ao serviço `waha` do compose do staging no Coolify:
  `WHATSAPP_FILES_LIFETIME=1209600`, `WAHA_SESSION_CONFIG_IGNORE_STATUS=true`, `WAHA_SESSION_CONFIG_IGNORE_GROUPS=true`,
  `WAHA_SESSION_CONFIG_IGNORE_CHANNELS=true`, `WAHA_SESSION_CONFIG_IGNORE_BROADCAST=true`, `WAHA_APPS_ENABLED=True`,
  `WAHA_APPS_ON=calls`, e trocar o `WHATSAPP_FILES_LIFETIME: "0"`. Reiniciar só o `waha`.
  Antes de reiniciar, plantar um arquivo velho para provar a varredura da partida (o staging foi zerado em 23/11, então o
  `du` sozinho não cai): `touch -d '20 days ago' /app/.media/Principal/zz-teste.bin` no contêiner `waha`.
- [ ] **Step 2:** Depois do reinício: `ls /app/.media/Principal/zz-teste.bin` some em segundos; status da sessão `WORKING`.
- [ ] **Step 3:** No terminal do contêiner `app` (sessão `Principal`; `S` = `"$WAHA_URL/api/sessions/Principal"`), cada
  comando seguido de `curl -s -H "X-Api-Key: $WAHA_API_KEY" $S | grep -o '"status":"[A-Z_]*"'`:
  ```
  curl -s -X POST -H "X-Api-Key: $WAHA_API_KEY" -H 'Content-Type: application/json' "$WAHA_URL/api/apps" -d '{"app":"calls","session":"Principal","id":"app_Principal","config":{"dm":{"reject":true,"message":"Este número não recebe ligações. Envie sua mensagem em texto ou áudio que responderemos por aqui."},"group":{"reject":true}}}'
  curl -s -H "X-Api-Key: $WAHA_API_KEY" "$WAHA_URL/api/apps?session=Principal"
  curl -s -X PUT -H "X-Api-Key: $WAHA_API_KEY" -H 'Content-Type: application/json' "$WAHA_URL/api/apps/app_Principal" -d '<mesmo corpo com outro texto>'
  ```
  Esperado: o app listado, a alteração aceita e a sessão sempre `WORKING`. Se `group` sem `message` for recusado, repetir
  com o mesmo texto do `dm` e anotar: a Task 3 usa o texto nos dois. Anotar também o código HTTP de
  `GET /api/apps?session=NaoExiste` e de um `POST /api/apps` com `"session":"NaoExiste"`: a Task 3 conta como `skipped`
  exatamente a resposta que o WAHA dá para sessão inexistente (ajustar o teste `test_apply_all_pula_404_e_continua` a ela).
- [ ] **Step 4:** O dono liga para o número de teste: a ligação é recusada e a mensagem chega ao celular dele.
- [ ] **Step 5:** Conferir no app como a mensagem automática chegou: se apareceu um ticket "em atendimento" sem responsável
  ou uma mensagem "fora do painel" num ticket aberto (comportamento esperado do `IngestMessage` hoje), a Task 6 vale. Guardar
  no ledger os campos do `payload` do `waha_events` desse eco (sem texto de cliente nem telefone) ao lado dos de uma mensagem
  digitada no celular: se algum campo distingue a resposta do app `calls`, a Task 6 casa por ele. Se nenhum eco chegou ao
  app, a Task 6 sai do plano (`Desvio:` no ledger).
- [ ] **Step 6:** O dono manda uma mensagem de texto e uma imagem num grupo de que o número de teste participa: nenhum evento
  novo de grupo em Configurações > Canais (últimos eventos) e a contagem de arquivos em `/app/.media/Principal` não sobe.
- [ ] **Step 7:** Remover o app criado à mão (`curl -X DELETE ... "$WAHA_URL/api/apps/app_Principal"`), conferir o status
  `WORKING` de novo; o código recria.
- [ ] **Step 8:** Se o Step 3 mostrar que só reiniciando a sessão funciona, **parar** e perguntar ao dono.

### Task 2: Compose, mídia de 14 dias no `app:purge-media`, comentários e docs

**Files:**
- Modify: `docker-compose.yml` (serviço `waha`, linhas ~137-139)
- Modify: `app/Console/Commands/PurgeMediaCommand.php`
- Modify: `app/Jobs/ResolveMessageMediaJob.php:96` (comentário)
- Modify: `app/Services/Inbox/RecoverMessages.php:21` (comentário)
- Modify: `docs/security.md` (tabela "Retenção dos dados guardados")
- Modify: `docs/deploy.md`
- Test: `tests/Feature/Inbox/PurgeMediaTest.php`

**Interfaces:**
- Produces: `PurgeMediaCommand::WAHA_FILES_DAYS = 14` (public const).

- [ ] **Step 1: Testes que falham** em `PurgeMediaTest` (helper novo `waiting(int $ageDays, string $type = 'media'): Message`
  cria mensagem de entrada com `type`, `media_ref = 'abc.jpg'`, `media_path = null` e `created_at` antigo):
  - `test_midia_sem_copia_com_mais_de_14_dias_expira`: 15 dias → `media_purged_at` não nulo e 1 evento `media_purged` com o
    `message_id` dela.
  - `test_midia_sem_copia_com_menos_de_14_dias_fica`: 13 dias → `media_purged_at` nulo, nenhum evento.
  - `test_midia_com_copia_de_20_dias_segue_os_90`: `attachment(..., 20)` → arquivo existe, `media_purged_at` nulo.
  - `test_texto_antigo_sem_midia_nao_e_marcado`: `waiting(30, 'text')` com `media_ref` nulo → `media_purged_at` nulo.
  - `test_midia_sem_copia_ja_expirada_nao_gera_evento_de_novo`: rodar o comando duas vezes com uma de 15 dias → 1 evento.
- [ ] **Step 2:** `php artisan test --filter=PurgeMediaTest` → os novos falham (os 4 primeiros por `media_purged_at` nulo).
- [ ] **Step 3:** Em `PurgeMediaCommand`: `public const WAHA_FILES_DAYS = 14;` com comentário de uma linha ("espelha
  `WHATSAPP_FILES_LIFETIME` do serviço `waha` no `docker-compose.yml`; o app não enxerga aquela variável"). Segundo laço no
  `handle()`: `type = 'media'`, `media_path` nulo, `media_purged_at` nulo, `created_at < now()->subDays(self::WAHA_FILES_DAYS)`
  → `media_purged_at = now()` e evento `media_purged` igual ao do laço de 90 dias; `info("Anexos sem cópia expirados: N")`.
  Atualizar `$description` para citar as duas regras.
- [ ] **Step 4:** `php artisan test --filter=PurgeMediaTest` → tudo PASS.
- [ ] **Step 5:** Compose: `WHATSAPP_FILES_LIFETIME: "1209600"` e, no mesmo serviço, `WAHA_SESSION_CONFIG_IGNORE_STATUS`,
  `_GROUPS`, `_CHANNELS`, `_BROADCAST` = `"true"`, `WAHA_APPS_ENABLED: "True"`, `WAHA_APPS_ON: "calls"`. Comentários de
  código: `ResolveMessageMediaJob::copyToDisk` diz "o WAHA o mantém por 14 dias"; `RecoverMessages::MAX_WINDOW_DAYS` perde a
  frase "o GOWS guarda só alguns dias" (fica: teto da janela de recuperação). `docs/security.md`: linha "Arquivo de mídia no
  WAHA (`waha-media`) | 14 dias | o próprio WAHA (`WHATSAPP_FILES_LIFETIME`)", linha "Mensagens de grupo, status, canal e
  lista de transmissão | não recebidas nem gravadas | `WAHA_SESSION_CONFIG_IGNORE_*`" e uma frase abaixo da tabela: anexo
  recebido sem cópia no disco expira com 14 dias. `docs/deploy.md`: parágrafo curto "Atualizando uma instalação existente"
  com as variáveis novas do serviço `waha`, que o Coolify não aplica sozinho ao compose já instalado (editar e reiniciar o
  `waha`).
- [ ] **Step 6: Commit** `feat: mídia do WAHA dura 14 dias e chats de grupo e status deixam de ser recebidos`

### Task 3: `CallSettings` e `WahaCalls`

**Files:**
- Create: `app/Settings/CallSettings.php`
- Create: `app/Services/Waha/WahaCalls.php`
- Test: `tests/Feature/Services/WahaCallsTest.php`

**Interfaces:**
- Produces:
  - `CallSettings::rejects(): bool` (`Setting::get('calls.reject', true)`), `CallSettings::message(): string` (texto salvo
    ou `CallSettings::DEFAULT_MESSAGE`), `CallSettings::save(bool $reject, string $message): void`,
    `CallSettings::MAX_MESSAGE = 500`.
  - `CallSettings::knownMessages(): list<string>` — o texto atual mais os anteriores (chave `calls.previous_messages`; o
    `save` guarda o texto que sai quando ele muda, no máximo 10, sem repetir, incluindo o padrão quando ele é trocado). A
    Task 6 usa isto para reconhecer respostas antigas que a recuperação relê do WAHA.
  - `WahaCalls::apply(Channel $channel): void` — lança `WahaRequestException`/`ConnectionException`.
  - `WahaCalls::applyQuietly(Channel $channel): void` — nunca lança; falha vai a `Log::warning`.
  - `WahaCalls::applyAll(): array{applied: int, unchanged: int, failed: int}` — canais com `active = true` e status
    `WORKING`; `unchanged` quando o app já está como deveria (nenhuma escrita), falha em `failed` (com `Log::warning`), e
    nunca interrompe os demais.
  - `WahaCalls::apply(Channel $channel): bool` — `true` quando escreveu no WAHA, `false` quando já estava igual (substitui o
    `void` acima).

- [ ] **Step 1: Testes que falham** (`config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'k'])`,
  canal `waha_session = 'main'`):
  - `test_padrao_recusa_com_o_texto_padrao`: sem nada salvo → `rejects()` true e `message()` igual ao texto das Global
    Constraints.
  - `test_textos_anteriores_ficam_conhecidos`: `save(true, 'A')`, `save(true, 'B')` → `knownMessages()` contém `B`, `A` e o
    padrão; salvar `B` de novo não duplica; 12 trocas guardam no máximo 10 anteriores.
  - `test_cria_o_app_quando_nao_existe`: `GET */api/apps?session=main` → `[]`, `POST */api/apps` → 201. Assert do POST:
    `app = 'calls'`, `id = 'app_main'`, `session = 'main'`, `config.dm.reject = true`, `config.dm.message` = texto salvo,
    `config.group.reject = true` e sem chave `message` em `group`.
  - `test_atualiza_o_app_que_ja_existe`: GET devolve `[['id' => 'app_main', 'app' => 'calls', 'enabled' => true, 'config' => <texto antigo>]]`
    → `PUT */api/apps/app_main` com o corpo novo; nenhum POST.
  - `test_app_igual_nao_escreve`: GET devolve o app com `enabled` true e `config` idêntico ao desejado → só o GET, `apply`
    devolve `false`.
  - `test_chave_desligada_remove_o_app`: `save(false, ...)`, GET devolve o app → `DELETE */api/apps/app_main`; GET vazio →
    nenhuma chamada além do GET.
  - `test_nunca_reinicia_a_sessao`: em todos os casos acima, `Http::assertNotSent` de `PUT */api/sessions/*`.
  - `test_apply_all_so_canais_ativos_e_conectados`: um canal `WORKING` sem app, um `WORKING` com app igual, um `STOPPED` e
    um inativo → `['applied' => 1, 'unchanged' => 1, 'failed' => 0]`; nenhuma chamada com a sessão dos dois últimos.
  - `test_apply_all_conta_falha_e_continua`: primeiro canal 500, segundo ok → `failed` 1, `applied` 1.
  - `test_apply_quietly_nao_lanca`: WAHA 500 → sem exceção, `Log::warning` chamado.
- [ ] **Step 2:** `php artisan test --filter=WahaCallsTest` → FAIL (classes não existem).
- [ ] **Step 3:** Implementar `CallSettings` no padrão de `CompanySettings` (chaves `calls.reject` e `calls.message`) e
  `WahaCalls` usando `new WahaClient($channel)`, `->http()` e `->check()`. Se o portão (Task 1, Step 3) mostrou que `group` sem
  `message` é recusado, `group` leva o mesmo texto do `dm` e o teste de criação muda junto.
- [ ] **Step 4:** `php artisan test --filter=WahaCallsTest` → PASS.
- [ ] **Step 5: Commit** `feat: configuração de recusa de ligações e aplicação no WAHA por sessão`

### Task 4: Seção "Ligações" em Configurações > Canais

**Files:**
- Modify: `app/Livewire/Settings/Channels.php`
- Modify: `resources/views/livewire/settings/channels.blade.php` (novo `<x-panel title="Ligações">` logo depois do painel
  "Adicionar número")
- Modify: `app/Enums/AuditAction.php` (caso `CallSettingsUpdated = 'calls.settings_updated'`, rótulo "Ligações alteradas",
  grupo "Configurações")
- Test: `tests/Feature/Livewire/ChannelsCallsTest.php`

**Interfaces:**
- Consumes: `CallSettings::rejects/message/save/MAX_MESSAGE`, `WahaCalls::applyAll()` (Task 3).
- Produces: propriedades `public bool $rejectCalls`, `public string $callMessage`; método `saveCalls(): void`; propriedade
  `public string $callsNotice` (texto do resultado).

- [ ] **Step 1: Testes que falham** (admin via `User::factory()->create(['role' => UserRole::ADMIN])`, `Http::fake` do WAHA):
  - `test_mostra_a_chave_ligada_e_o_texto_padrao`: `assertSet('rejectCalls', true)` e `assertSet('callMessage', <padrão>)`,
    `assertSee('Recusar ligações')`.
  - `test_salvar_grava_aplica_e_audita`: `set('callMessage', 'Não atendemos ligações.')->call('saveCalls')` →
    `CallSettings::message()` igual, um `POST */api/apps` enviado, uma linha `audit_logs` com `action = 'calls.settings_updated'`
    e `callsNotice` contendo "aplicada".
  - `test_waha_fora_do_ar_salva_e_avisa`: WAHA responde 500 → configuração salva e `callsNotice` contendo "será aplicada".
  - `test_texto_invalido_com_chave_ligada_e_recusado`: `''`, `'   '`, texto com `"\x07"` e texto com 501 caracteres →
    `assertHasErrors('callMessage')` e nada salvo.
  - `test_chave_desligada_aceita_texto_vazio`: `rejectCalls = false`, `callMessage = ''` → salvo sem erro.
  - `test_nao_admin_recebe_403`: atendente chamando `saveCalls` → `assertForbidden()`.
- [ ] **Step 2:** `php artisan test --filter=ChannelsCallsTest` → FAIL.
- [ ] **Step 3:** `mount()` carrega da `CallSettings`. `saveCalls()`: `$this->authorize('admin')`; valida
  `callMessage` com `required_if:rejectCalls,true`, `string`, `max:CallSettings::MAX_MESSAGE` e `new NoControlChars(allowNewlines: true)`,
  com `trim` antes; `CallSettings::save`; `Auditor::record(AuditAction::CallSettingsUpdated, auth()->user(), detail: ['reject' => ...])`
  (sem o texto no detalhe); `WahaCalls::applyAll()` e `callsNotice` = "Configuração aplicada aos canais." quando `failed`
  é 0, senão "Configuração salva; será aplicada aos canais quando o WhatsApp responder.". Blade: `flux:switch` "Recusar
  ligações", `flux:textarea` "Mensagem enviada a quem ligar" (desabilitado com a chave desligada), botão "Salvar", texto de
  ajuda curto dizendo que a ligação para de tocar também no celular e que **salvar uma mudança reinicia a conexão do
  WhatsApp por alguns segundos**, e o `callsNotice` com `role="status"`.
- [ ] **Step 4:** Testes afetados (comando do CLAUDE.md; mudança em Blade não aparece nele, então também
  `--filter="ChannelsCallsTest|ChannelsScreenTest"`) → PASS.
- [ ] **Step 5: Commit** `feat: seção Ligações em Configurações > Canais`

### Task 5: Aplicar ao conectar e conferência diária

**Files:**
- Modify: `app/Livewire/Settings/ChannelCard.php` (as duas chamadas de `->start()`, linhas ~82 e ~153)
- Create: `app/Console/Commands/ApplyCallPolicyCommand.php` (`app:apply-call-policy`)
- Modify: `routes/console.php` (`Schedule::command('app:apply-call-policy')->dailyAt('04:05')->withoutOverlapping(10);`)
- Test: `tests/Feature/Console/ApplyCallPolicyCommandTest.php`, `tests/Feature/Livewire/ChannelCardCallsTest.php`

**Interfaces:**
- Consumes: `WahaCalls::applyQuietly(Channel)`, `WahaCalls::applyAll()` (Task 3).

- [ ] **Step 1: Testes que falham:**
  - `ChannelCardCallsTest::test_conectar_aplica_o_app_de_ligacoes`: fake de `*/api/sessions/main` 404, `*/api/sessions` 201,
    `*/api/apps*` `[]`/201 → `call('connect')` envia `POST */api/apps` e o status vai a `STARTING`.
  - `ChannelCardCallsTest::test_falha_no_app_de_ligacoes_nao_impede_conectar`: `*/api/apps*` 500 → status `STARTING`, sem
    `errorMessage`.
  - `ApplyCallPolicyCommandTest::test_aplica_em_todos_os_canais_ativos_e_resume`: dois canais ativos ok → comando com
    sucesso e saída contendo "aplicados: 2".
  - `ApplyCallPolicyCommandTest::test_esta_agendado_todo_dia`: o agendamento contém `app:apply-call-policy` (mesmo padrão do
    `test_esta_agendado_para_rodar_todo_dia` do `PurgeMediaTest`).
- [ ] **Step 2:** `php artisan test --filter="ChannelCardCallsTest|ApplyCallPolicyCommandTest"` → FAIL.
- [ ] **Step 3:** `ChannelCard`: depois de cada `start()` bem-sucedido, `app(WahaCalls::class)->applyQuietly($this->channel)`.
  Comando: chama `applyAll()` e imprime `aplicados: N, pulados: N, falhas: N`; sai com sucesso mesmo com falhas (a próxima
  rodada corrige). Agendamento às 04:05. O `TestCase` não bloqueia requisição sem fake: todo teste existente que chama
  `connect` ou o segundo caminho de `start()` do `ChannelCard` ganha o fake `'*/api/apps*' => Http::response([], 200)` (e
  `201` para o POST), para não fazer chamada real engolida pelo `applyQuietly`.
- [ ] **Step 4:** Testes afetados (comando do CLAUDE.md:
  `git status --porcelain | cut -c4- | codegraph affected --stdin -d 2 --quiet | xargs -r php artisan test`) → PASS.
- [ ] **Step 5: Commit** `feat: ligações recusadas aplicadas ao conectar e conferidas todo dia`

### Task 6: Ignorar o eco da resposta automática de ligação

Só se o portão (Task 1, Step 5) mostrou o eco chegando ao app.

**Files:**
- Modify: `app/Services/Inbox/IngestMessage.php` (`__invoke`, antes do `matchPending`)
- Test: `tests/Feature/Inbox/CallReplyEchoTest.php`

**Interfaces:**
- Consumes: `CallSettings::rejects()`, `CallSettings::message()` (Task 3).

- [ ] **Step 1: Testes que falham** (evento `message.any` `fromMe` de texto, no padrão dos testes de `IngestMessageTest`):
  - `test_eco_da_mensagem_de_ligacao_sem_ticket_nao_abre_ticket`: corpo igual a `CallSettings::message()` → retorno `null`,
    nenhum `Ticket` e nenhuma `Message`.
  - `test_eco_da_mensagem_de_ligacao_nao_para_o_sla_do_ticket_aberto`: ticket aberto aguardando → nenhuma `Message` nova e
    `last_outbound_at` do ticket inalterado.
  - `test_texto_diferente_do_configurado_entra_como_hoje`: corpo diferente → mensagem gravada com origem `FORA_DO_PAINEL`.
  - `test_com_a_chave_desligada_o_eco_entra_como_hoje`: `CallSettings::save(false, <mesmo texto>)` → mensagem gravada.
  - `test_texto_antigo_relido_pela_recuperacao_depois_da_troca_e_ignorado`: salvar `A`, depois `B`; ingerir no modo
    `recovered` um eco `fromMe` com texto `A` → nenhum `Ticket` nem `Message`.
- [ ] **Step 2:** `php artisan test --filter=CallReplyEchoTest` → FAIL.
  - `test_mesmo_texto_digitado_no_celular_entra_como_hoje`: corpo igual ao configurado, mas `source: 'app'` → gravada.
- [ ] **Step 3:** No `__invoke`, depois de resolver `$incoming` e antes do contato: `fromMe`, `type === 'text'`, `source`
  do payload (`$event->payload['payload']['source']`) igual a `'api'`, `CallSettings::rejects()` ligado e
  `trim($incoming->body)` presente em `CallSettings::knownMessages()` (comparando com `trim`) → `return null`. Os testes dos
  ecos ignorados levam `source: 'api'`. Comentário:
  `shortcut: casa pela origem API mais o texto; a mesma frase enviada pelo painel e com eco fora da janela também seria ignorada. Trocar por marca do WAHA se ele passar a identificar a resposta do app calls.`
  Limite aceito, anotar no ledger: o `message.ack` do eco ignorado vira o aviso "Ack descartado" no log a cada ligação
  recusada.
- [ ] **Step 4:** Testes afetados (comando do CLAUDE.md) → PASS.
- [ ] **Step 5: Commit** `fix: resposta automática de ligação não abre ticket nem para o SLA`

### Task 7: Verificação, staging e docs de instalação

- [ ] **Step 1:** Portão 7 do CLAUDE.md (Pint, PHPStan, PHPMD, `node --test tests/js`, `npm run build`, suíte com Redis),
  lendo o código de saída de cada um.
- [ ] **Step 2:** Conferência visual da seção Ligações pelo agente `conferente-visual` nas resoluções 720p, 1080p e 2K
  (servidor local com o `AmbienteDeTesteSeeder`).
- [ ] **Step 3:** Depois do merge e da pré-release, com o OK do dono: no staging, a seção Ligações aplica e uma ligação de teste
  é recusada com o texto da tela. Na production, as variáveis do compose só com o OK do dono.
