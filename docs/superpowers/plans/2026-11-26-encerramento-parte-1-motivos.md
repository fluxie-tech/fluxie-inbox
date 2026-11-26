# Encerramento, parte 1: motivos de encerramento editáveis

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** os motivos do painel Encerrar passam a viver numa tabela que o admin edita, e o resumo do atendimento deixa de ser sempre obrigatório.

**Architecture:**
- Tabela `close_reasons` e `tickets.close_reason_id`, numa migração só aditiva. Ela insere os 3 motivos de hoje, preenche os chamados antigos e reescreve os eventos `closed` antigos.
- O encerramento, as leituras (histórico, linha do tempo, cartões) e uma tela nova "Encerramento" passam a usar o model `CloseReason`.
- O enum `CloseOutcome` sai. A coluna `close_outcome` fica no banco, sem uso, até uma versão seguinte.

**Tech Stack:** Laravel 13, Livewire 4.4, Flux 2 (free), PostgreSQL 18, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-26-encerramento-atendimento-design.md` (Parte 1).

## Global Constraints

- **Migração só aditiva.** Nada de `dropColumn`/`drop` no `up()`, por causa da trava `tests/js/migration-guard.test.mjs`. `close_outcome` fica no banco.
- **Motivos iniciais,** nesta ordem e com `requires_summary = false`:

  | `position` | Nome | Slug antigo |
  |------------|------|-------------|
  | 1 | Resolvido | `resolvido` |
  | 2 | Sem resposta | `sem_resposta` |
  | 3 | Cliente pediu | `cliente_pediu` |

- **Nome do motivo:** obrigatório, até 60 caracteres, único sem diferenciar maiúsculas e minúsculas (índice em `lower(name)`), com a regra `App\Rules\NoControlChars`.
- **Resumo:** obrigatório só quando o motivo pede. Preenchido, vale de 3 a 500 caracteres. O texto é aparado com `trim` antes de validar.
- **Selo do motivo:** `<x-status-badge tone="neutral">{{ nome }}</x-status-badge>` em todo lugar.
- **Tela "Encerramento":** só admin. Rota `configuracoes/encerramento`, com o nome `configuracoes.encerramento`, no grupo `can:admin`. Fica no menu no grupo "Atendimento", logo depois de "Horário e SLA".
- **Acoplamento (PHPMD) de no máximo 13 por classe, sem baseline.** `Livewire\Inbox\Inbox` não importa `CloseReason`: tudo que é motivo passa por `TicketPanel`.
- **Textos em português.** Commits em português, com prefixo, e as linhas de atribuição do lembrete.
- **Antes de cada suíte:** `docker ps` com o `pg-test` (e o `redis-test` no portão 7) no ar, como manda o `CLAUDE.md`.

## Review Focus

1. **URL antiga do histórico com `?outcome=resolvido`** (favorito ou link salvo): a página abre sem erro e sem filtro de motivo. Teste na Tarefa 3.
2. **Motivo desativado por um admin enquanto o atendente está com o painel Encerrar aberto e esse motivo escolhido:** o encerramento é recusado com "Escolha o motivo do encerramento." e o chamado continua aberto. Teste na Tarefa 2.
3. **Renomear um motivo já usado:** o chamado antigo mostra o nome novo no histórico, no cartão do contato e na linha do tempo (o evento antigo também). Testes nas Tarefas 1 e 3.
4. **Resumo só de espaços num motivo que pede resumo:** é recusado com "Escreva um resumo do atendimento.". Teste na Tarefa 2.
5. **"resolvido" digitado quando já existe "Resolvido"** é recusado como duplicado, e desativar o último motivo ativo é recusado. Testes na Tarefa 4.

---

### Task 1: tabela, model e migração dos dados antigos

**Files:**
- Create: `database/migrations/2026_11_26_000001_create_close_reasons.php`
- Create: `app/Models/CloseReason.php`
- Create: `database/factories/CloseReasonFactory.php`
- Modify: `app/Models/Ticket.php` (relação `closeReason`, `@property int|null $close_reason_id`)
- Test: `tests/Feature/Models/CloseReasonsMigrationTest.php`

**Interfaces:**
- Produces:
  - **`App\Models\CloseReason`**
    - Atributos: `id`, `name` (string), `requires_summary` (bool), `active` (bool), `position` (int).
    - `scopeActive(Builder $q): void`.
    - `scopeOrdered(Builder $q): void`, que ordena por `position` e depois por `id`.
    - `isUsed(): bool`, verdadeiro quando existe ticket com `close_reason_id` igual ao dele.
  - **`Ticket::closeReason(): BelongsTo<CloseReason, $this>`.**
  - **No banco:**
    - `close_reasons` com `timestamps`;
    - `tickets.close_reason_id`, `foreignId` nulo, `constrained()->restrictOnDelete()`;
    - eventos `closed` antigos com `payload.close_reason_id`.

- [ ] **Step 1: Escrever os testes que falham.** No molde de `tests/Feature/Models/AgentSeenBackfillTest.php` (`migrate:rollback --path` e depois `migrate --path`):

```php
public function test_a_migracao_cria_os_tres_motivos_na_ordem(): void
{
    $this->assertSame(['Resolvido', 'Sem resposta', 'Cliente pediu'], CloseReason::query()->ordered()->pluck('name')->all());
    $this->assertSame(0, CloseReason::query()->where('requires_summary', true)->count());
    $this->assertSame(3, CloseReason::query()->active()->count());
}

public function test_a_migracao_liga_os_tickets_e_os_eventos_antigos_ao_motivo(): void
{
    Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]);
    // ticket encerrado com close_outcome = 'sem_resposta', outro com null; TicketEvent 'closed' com payload ['reason' => 'manual', 'outcome' => 'sem_resposta']
    Artisan::call('migrate', ['--path' => self::MIGRATION]);

    $id = CloseReason::query()->where('name', 'Sem resposta')->value('id');
    $this->assertSame($id, $comMotivo->fresh()->close_reason_id);
    $this->assertNull($semMotivo->fresh()->close_reason_id);
    $this->assertSame($id, $evento->fresh()->payload['close_reason_id']);
}

public function test_nome_repetido_sem_diferenciar_maiusculas_e_barrado_pelo_banco(): void
{
    $this->expectException(UniqueConstraintViolationException::class);
    CloseReason::factory()->create(['name' => 'resolvido']);
}
```

- [ ] **Step 2: Rodar e ver falhar.** Comando: `php artisan test --filter=CloseReasonsMigrationTest`. Esperado: FAIL, com a classe `CloseReason` inexistente.
- [ ] **Step 3: Escrever a migração.**
  - Cria a tabela e o índice único `close_reasons_name_lower_unique` em `lower(name)`, com `DB::statement`.
  - Insere os 3 motivos.
  - Faz `update tickets set close_reason_id = … where close_outcome = '<slug>'` para cada slug.
  - Reescreve os eventos `type = 'closed'` cujo `payload` (JSON, não JSONB) tem `outcome`, acrescentando `close_reason_id`. Faz isso em PHP, com `chunkById`, sem tirar `outcome`.
  - O `down()` desfaz tudo; ali `drop` é permitido.
- [ ] **Step 4: Escrever o model, a factory e a relação.**
  - Model com casts `requires_summary` e `active` como `boolean`, e atributo `#[Fillable]`.
  - A factory gera um nome único e `position` 99.
- [ ] **Step 5: Rodar e ver passar.** Comando: `php artisan test --filter=CloseReasonsMigrationTest`. Esperado: PASS.
- [ ] **Step 6: Commit.** Mensagem: `feat: tabela close_reasons com os motivos de hoje e migração dos chamados antigos`.

### Task 2: encerrar com o motivo da tabela e o resumo conforme o motivo

**Files:**
- Modify:
  - `app/Actions/Inbox/CloseTicket.php`
  - `app/Services/Inbox/TicketPanel.php`
  - `app/Livewire/Inbox/Inbox.php` (`closeOutcome` → `closeReasonId`, `close()`, `closePanel()`, `render()`)
  - `resources/views/livewire/inbox/inbox.blade.php` (painel Encerrar, linhas ~320–335)
- Test:
  - `tests/Feature/Inbox/CloseWithSummaryTest.php` (adaptar)
  - `tests/Feature/Livewire/InboxTest.php` (adaptar os encerramentos)

**Interfaces:**
- Consumes: `CloseReason`, `Ticket::closeReason()` (Tarefa 1).
- Produces:
  - **`CloseTicket::__invoke(Ticket $ticket, ?User $user, ClosedReason $reason = ClosedReason::MANUAL, ?CloseReason $closeReason = null, ?string $summary = null): bool`**
    - Grava `close_reason_id` e não grava mais `close_outcome`.
    - O payload do evento é `array_filter(['reason' => …, 'close_reason_id' => $closeReason?->id, 'summary' => $summary])`.
  - **`TicketPanel::closeRules(?int $closeReasonId): array`**
    - `closeReasonId`: `required`, `integer`, `Rule::exists('close_reasons', 'id')->where('active', true)`.
    - `closeSummary`: `required` se o motivo ativo `$closeReasonId` tiver `requires_summary`, senão `nullable`; depois `string`, `min:3`, `max:500`.
  - **`TicketPanel::CLOSE_MESSAGES`**
    - `closeReasonId.required` e `closeReasonId.exists`: "Escolha o motivo do encerramento.".
    - As mensagens de `closeSummary` ficam como estão.
  - **`TicketPanel::close(Ticket $ticket, User $by, int $closeReasonId, string $summary): bool`:** resumo vazio vira `null`.
  - **`TicketPanel::closeReasons(): Collection<int, CloseReason>`:** os ativos, em `ordered()`.
  - **`Inbox::$closeReasonId`:** `string`, vazio por padrão, com `wire:model.live` no select para o texto de ajuda acompanhar o motivo.
  - **`render()` passa à view:**
    - `closeReasons` (só quando `panel === 'close'`, senão `collect()`);
    - `summaryRequired` (bool, do motivo escolhido).

- [ ] **Step 1: Escrever os testes que falham,** em `CloseWithSummaryTest`, adaptando os que usam `CloseOutcome`:

```php
public function test_motivo_que_nao_pede_resumo_encerra_sem_resumo(): void
// Livewire Inbox: set('closeReasonId', id de 'Resolvido')->call('close') => status ENCERRADO, close_reason_id = Resolvido, close_summary null

public function test_motivo_que_pede_resumo_recusa_resumo_em_branco(): void
// motivo com requires_summary = true; closeSummary '   ' => assertHasErrors(['closeSummary' => 'required']); ticket continua aberto

public function test_resumo_preenchido_curto_demais_e_recusado_mesmo_quando_opcional(): void
// closeSummary 'ok' => assertHasErrors(['closeSummary' => 'min'])

public function test_motivo_desativado_com_o_painel_aberto_e_recusado(): void
// escolhe o motivo, desativa no banco, call('close') => assertHasErrors(['closeReasonId']); ticket continua aberto

public function test_evento_de_encerramento_guarda_o_id_do_motivo(): void
// (new CloseTicket)($ticket, $user, ClosedReason::MANUAL, $motivo, 'x') => payload close_reason_id = $motivo->id; sem chave 'outcome'

public function test_o_painel_lista_so_os_ativos_na_ordem_e_avisa_quando_o_resumo_e_obrigatorio(): void
// motivo inativo não aparece; com o motivo que pede resumo escolhido, a tela mostra 'Obrigatório para este motivo.'
```

- [ ] **Step 2: Rodar e ver falhar.** Comando: `php artisan test --filter=CloseWithSummaryTest`. Esperado: FAIL.
- [ ] **Step 3: Implementar as assinaturas acima.**
  - O texto de ajuda do resumo é `Obrigatório para este motivo. Aparece no histórico do contato e na auditoria.` quando `summaryRequired`, e `Opcional. Aparece no histórico do contato e na auditoria.` nos outros casos.
  - O select lista `$closeReasons` por `id`/`name`, com a opção vazia "Escolha o motivo".
  - `LiveRecords::close()` continua com 3 argumentos.
- [ ] **Step 4: Rodar e ver passar.** Comando: `php artisan test --filter='CloseWithSummaryTest|InboxTest|ClaimAndCloseTest|RoutingActionsTest|BroadcastTest'`. Esperado: PASS.
- [ ] **Step 5: Commit.** Mensagem: `feat: encerrar com motivo da tabela e resumo obrigatório só quando o motivo pede`.

### Task 3: leituras pelo motivo da tabela e fim do enum `CloseOutcome`

**Files:**
- Modify:
  - `app/Services/Inbox/HistorySearch.php`
  - `app/Livewire/History/Index.php`
  - `app/Support/TicketTimeline.php` (`closedDetail`)
  - `app/Support/TicketEventText.php` (`detail`)
  - `app/Models/Ticket.php`: tirar o cast de `close_outcome` e a anotação dele.
  - As consultas que montam as listas, para o `with('closeReason')`:
    - `app/Livewire/History/Index.php` (lista);
    - `app/Services/Inbox/TicketQueueQuery.php` (aba Encerrados);
    - `app/Services/Contacts/ContactCardData.php` (chamados anteriores).
  - Views:
    - `resources/views/components/ticket-details.blade.php:23`
    - `resources/views/livewire/contacts/contact-card.blade.php:58,163`
    - `resources/views/livewire/history/index.blade.php:45-49,92-93,134-135`
    - `resources/views/livewire/inbox/inbox.blade.php:86,248-249`
- Delete: `app/Enums/CloseOutcome.php`
- Test (adaptar e acrescentar):
  - `tests/Feature/History/HistorySearchTest.php`
  - `tests/Feature/History/HistoryPageTest.php`
  - `tests/Feature/History/InboxClosedTabTest.php`
  - `tests/Feature/Livewire/ContactCardTest.php`
  - `tests/Feature/Components/StatusBadgeScreensTest.php`
  - `tests/Unit/EnumToneTest.php`
  - `tests/Feature/Timeline/TicketTimelineTest.php`

**Interfaces:**
- Consumes: `CloseReason`, `Ticket::closeReason()`, `payload.close_reason_id` (Tarefas 1 e 2).
- Produces:
  - **`HistorySearch::query(User $user, string $q = '', ?string $from = null, ?string $to = null, ?int $sectorId = null, ?int $userId = null, ?int $closeReasonId = null, ?int $contactId = null): Builder`:** filtra por `close_reason_id`.
  - **`History\Index::$outcome`** continua `string` e continua com `#[Url]` (o nome do parâmetro não muda).
    - Só vira filtro quando `ctype_digit($this->outcome)`.
    - `outcomes` passa a ser `CloseReason::query()->ordered()->get()`, com todos os motivos. Os desativados aparecem com o sufixo " (desativado)".
  - **Nome do motivo no texto do evento:**
    - `TicketTimeline` carrega o mapa `id => name` de `close_reasons` no construtor, como faz com `sectors` e `users`.
    - `TicketEventText::detail` usa `once(fn () => CloseReason::query()->pluck('name', 'id'))`.
    - Id desconhecido: "motivo removido".

- [ ] **Step 1: Escrever os testes que falham.**

```php
// HistoryPageTest
public function test_url_antiga_com_slug_no_motivo_abre_sem_filtro(): void
// get(route('historico', ['outcome' => 'resolvido'])) => 200 e o ticket encerrado com outro motivo aparece

public function test_filtrar_pelo_motivo_desativado_ainda_funciona(): void

// HistorySearchTest
public function test_filtra_pelo_id_do_motivo(): void

// TicketTimelineTest
public function test_renomear_o_motivo_muda_o_texto_do_evento_antigo(): void
// evento closed com close_reason_id; renomeia o motivo; detail() contém o nome novo

// ContactCardTest
public function test_chamado_anterior_mostra_o_selo_cinza_com_o_nome_do_motivo(): void
// assertSee('data-status-tone="neutral"', false) e o nome
```

- [ ] **Step 2: Rodar e ver falhar.** Comando: `php artisan test --filter='HistoryPageTest|HistorySearchTest|TicketTimelineTest|ContactCardTest'`. Esperado: FAIL.
- [ ] **Step 3: Implementar.**
  - Trocar todo `close_outcome` lido no PHP e nas views por `closeReason`, com o selo cinza dos Global Constraints.
  - Apagar o enum e os casos dele em `EnumToneTest` e `StatusBadgeScreensTest`.
  - Conferir com `git grep -n "CloseOutcome\|close_outcome" app resources tests`: só podem restar a migração antiga e a nova.
- [ ] **Step 4: Rodar e ver passar.** Comando: `php artisan test --parallel`, a suíte inteira, porque o enum some. Esperado: PASS.
- [ ] **Step 5: Commit.** Mensagem: `refactor: histórico, linha do tempo e cartões leem o motivo da tabela; sai o enum CloseOutcome`.

### Task 4: tela "Encerramento" com os motivos

**Files:**
- Create:
  - `app/Livewire/Settings/Closing.php`
  - `resources/views/livewire/settings/closing.blade.php`
  - `app/Actions/CloseReasons/SaveCloseReason.php`
  - `app/Actions/CloseReasons/CloseReasonRejected.php` (exceção com a mensagem em português)
- Modify:
  - `routes/web.php` (grupo `can:admin`)
  - `app/Support/Navigation.php`, com o item `$item('Encerramento', 'configuracoes.encerramento', 'admin', $config, 'Atendimento', 'check-circle')` depois de "Horário e SLA"
- Test: `tests/Feature/Livewire/ClosingPageTest.php`

**Interfaces:**
- Consumes: `CloseReason` (Tarefa 1).
- Produces:
  - **`Settings\Closing`** com `#[Title('Encerramento')]`, `mount()` com `authorize('admin')` e `public string $newName = ''`. Métodos, todos com `authorize('admin')`:
    - `create(string $name)`
    - `rename(int $id, string $name)`
    - `toggleSummary(int $id)`
    - `setActive(int $id, bool $active)`
    - `move(int $id, string $direction)`
    - `remove(int $id)`
  - **`SaveCloseReason`:**
    - `create(string $name): CloseReason`, com `position` igual ao maior mais 1;
    - `rename(CloseReason $r, string $name): void`;
    - `setActive(CloseReason $r, bool $active): void`;
    - `delete(CloseReason $r): void`.
    - Cada um lança `CloseReasonRejected` com a mensagem.
    - `setActive(false)` e `delete` travam as linhas ativas com `lockForUpdate`, numa transação.
  - **Mensagens:**
    - `Informe o nome do motivo.`
    - `O nome passa de 60 caracteres.`
    - `Já existe um motivo com esse nome.` (também ao pegar `UniqueConstraintViolationException`)
    - `Mantenha pelo menos um motivo ativo.`
    - `Não dá para apagar: o motivo já foi usado em chamados. Desative-o para tirá-lo do Encerrar.`
  - **Chaves de erro:** como em `Settings\Sectors`, isto é, `newName`, `rename.{id}`, `active.{id}` e `remove.{id}`.

- [ ] **Step 1: Escrever os testes que falham.**

```php
public function test_so_admin_abre_e_mexe(): void            // supervisor: 403 no get e no call('create')
public function test_cria_no_fim_da_lista(): void             // create('Orçamento enviado') => position 4, ativo, não pede resumo
public function test_nome_repetido_sem_diferenciar_maiusculas_e_recusado(): void // create('resolvido') => erro newName 'Já existe um motivo com esse nome.'
public function test_nome_vazio_longo_ou_com_caractere_de_controle_e_recusado(): void
public function test_renomear_muda_o_nome(): void
public function test_liga_e_desliga_pede_resumo(): void
public function test_desativar_o_ultimo_ativo_e_recusado(): void // desativa 2 dos 3; o 3º => erro 'Mantenha pelo menos um motivo ativo.'
public function test_reativar_volta_ao_encerrar(): void
public function test_mover_troca_a_ordem_e_no_limite_nao_faz_nada(): void
public function test_apagar_motivo_usado_e_recusado_e_o_sem_uso_some(): void
public function test_o_menu_mostra_encerramento_no_grupo_atendimento_so_para_admin(): void
```

- [ ] **Step 2: Rodar e ver falhar.** Comando: `php artisan test --filter=ClosingPageTest`. Esperado: FAIL.
- [ ] **Step 3: Implementar.**
  - **View no molde de `livewire/settings/sectors.blade.php`:**
    - `<x-settings-page route="configuracoes.encerramento" title="Encerramento" subtitle="Os motivos aparecem no Encerrar, nesta ordem. Desativado sai do Encerrar e continua no histórico. Só dá para apagar um motivo que nunca foi usado.">`.
    - Painel "Novo motivo" e painel "Motivos de encerramento".
    - Cada linha tem subir/descer, renomear (reusar o componente Alpine `sectorRow`, se ele servir sem mudar, senão um equivalente no mesmo arquivo de JS), o `flux:switch` "Pede resumo", o `flux:switch` "Ativo" e o botão Apagar.
  - A tela não usa `wire:confirm`: apagar só funciona em motivo sem uso.
- [ ] **Step 4: Rodar e ver passar.** Comando: `php artisan test --filter='ClosingPageTest|NavigationTest'`. Esperado: PASS.
- [ ] **Step 5: Portão parcial.** Comandos: `vendor/bin/pint --parallel --test --cache-file=.pint.cache`, `vendor/bin/phpstan analyse --no-progress` e `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml`. Esperado: código de saída 0 nos três.
- [ ] **Step 6: Commit.** Mensagem: `feat: tela Encerramento para o admin editar os motivos`.

---

Depois das tarefas, seguem a revisão do branch (`revisor-final`), a conferência visual (`conferente-visual`) do Encerrar, do histórico, do cartão do contato e da tela nova em 720p, 1080p e 2K, e o portão 7 completo. Só então vêm as partes 2 e 3, cada uma com o seu plano.
