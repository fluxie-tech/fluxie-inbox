# Leva de segurança e CI (itens 9 a 14 das pendências)

Data: 25/11/2026. Branch: `feat/seguranca-ci`, um PR só.

## Intenção

Fechar numa leva as pendências de segurança e CI anotadas antes da production ganhar número: enxergar violação da CSP em
produção, deixar o supervisor ler a auditoria de tickets, endurecer a análise estática (PHPMD moderno, regras de arquitetura) e
pôr no CI uma trava contra migração destrutiva. O dono quer tudo resolvido pelo método certo, sem adiar; o que é grande demais
para esta leva (refatorar as classes acopladas) vira a leva seguinte, já combinada.

## Objetivos

1. **Exceções do vite no `dependency-review`** — trazer o commit do branch `ci/remove-allow-ghsas-vite` (1547c88): sem
   `allow-ghsas` no `security.yml`. Conferido em 25/11: as faixas dos 4 GHSA não pegam o `vite-plus-core` 8.3.8000 e o
   `launch-editor` não está na árvore.
2. **Coletor de violações da CSP**, interno (regra do dono: nada de serviço externo).
3. **HSTS preload: não ligar**, com o motivo em `docs/security.md`.
4. **Supervisor lê a Auditoria de tickets** (`Audit\Index`); a aba Sistema (`Audit\SystemLog`) continua só do admin.
5. **Auto-merge do Dependabot: decisão do dono é não ter.** Registrar em `docs/security.md` (seção do Dependabot) e tirar a
   pendência. Dependency graph já está ligado (`docs/security.md:141`): só tirar da lista.
6. **PHPMD modernizado** (já na 3.0.0).
7. **Regras de arquitetura com PHPat**, reprovando o CI.
8. **Trava de migração destrutiva** no CI.
9. **Infection medido uma vez, localmente**, com relatório; nada no CI.

## Fora de escopo (não-objetivos)

- Geometria automática no Chrome do CI: leva própria (precisa de navegador no CI).
- Refatorar as 22 classes acima do limite de acoplamento: **leva seguinte**, que apaga o baseline e as exceções desta.
- Auto-merge do Dependabot, HSTS `preload`.
- Limite mínimo de escore do Infection no CI.

## Anti-objetivos

- O coletor da CSP não pode virar porta de inundação da tabela `audit_logs` nem do log.
- Nenhuma regra de arquitetura ou do PHPMD pode ser "passada" afrouxando limite sem o dono; exceção só nomeada e com motivo.
- A trava de migração não pode reprovar o `down()` (é lá que ficam os `dropColumn` legítimos: 52 migrations têm).

## Abordagens consideradas (escolhida: A)

- **A. Um branch e um PR, ~9 tarefas em sequência** — o "uma leva só" do dono; itens independentes, suíte com Redis uma vez no
  fim. Custo: PR grande. **Escolhida pelo dono.**
- B. Um PR por tema (CI, CSP+auditoria, qualidade estática) — revisões menores, mas três ciclos de CI sob `strict: true` (cada
  merge deixa os outros atrás).

Ferramentas, decididas pelo dono:
- Arquitetura: **PHPat** (phpat/phpat 0.12.5, set/2026, MIT; exige phpstan ^2.1, temos 2.2.13), roda dentro do
  `phpstan analyse` que o CI já roda. Descartado: Deptrac 4.7.2 (MIT) — ferramenta e comando à parte, camadas em YAML.
- Mutação: **Infection** (0.35.12, nov/2026, BSD-3-Clause, PHP ^8.3) só numa rodada local.

## Decisões do dono, por item

### 2. Coletor da CSP

- Rota interna `POST /csp-report`, sem login, fora da verificação de CSRF (`bootstrap/app.php`), com limite por IP
  (`config/security.php`, no padrão das chaves existentes) e corpo limitado em tamanho (o controller confere `Content-Length`
  ou o tamanho do corpo; o Laravel não limita sozinho). *Decisão minha:* sem sessão nem cookie (tirar `StartSession` e os
  middlewares de cookie da rota), para que cada relatório anônimo não abra sessão no Redis; fora do `RedirectToSetup` e da
  manutenção, respondendo 204 também nesses estados.
- A política passa a mandar os relatórios: `report-uri /csp-report` e `report-to csp` com o cabeçalho
  `Reporting-Endpoints: csp="<url>"` (a `report-uri` é a que todo navegador ainda manda; a `report-to` é a nova). Vale nos dois
  modos (`enforce` e `report-only`); `Reporting-Endpoints` com URL relativa (`csp="/csp-report"`). O teste do
  `SecurityHeaders` cobre os dois modos e o `off` (sem cabeçalho de relatório).
- Aceita os dois formatos: `application/csp-report` (`{"csp-report": {...}}`) e `application/reports+json` (lista de
  `{type: "csp-violation", body: {...}}`). Qualquer outra coisa: 204 sem gravar (não dar pista a quem sonda).
- Destino (decisão do dono): **log + linha na Auditoria › Sistema**. Ação nova no `AuditAction` (`csp.violation`, rótulo
  "Violação da CSP", grupo novo "Segurança" em `groups()`), gravada pelo `Auditor::record` sem autor, com `actor_label` fixo
  (ex.: "Navegador") e `detail` só com os campos úteis e truncados: diretiva violada, URI bloqueada, documento (sem query
  string), `source-file`/linha quando vierem, disposição. Nada de cookie, cabeçalho ou corpo inteiro.
- Antiinundação: violações iguais (mesma diretiva + URI bloqueada + documento) dentro de 1 hora viram **uma** linha; o log
  também. *Decisão minha:* `Cache::add` com chave por hash dos três campos e TTL de 1 h; o teste simula a expiração com
  `Cache::forget`. O case novo entra em `label()`, `group()` e `groups()`; rever o `AuditActionTest`. O `Auditor::record` já
  aceita autor nulo com `actorLabel` e nunca lança; o `ip` vem de `request()->ip()`.

### 3. HSTS preload

Não ligar. Motivos para `docs/security.md`: a lista de preload só aceita o cabeçalho servido no domínio raiz (o app roda em
subdomínio), e a imagem é instalada por terceiros (o `preload` iria para o domínio deles). O HSTS atual
(`max-age=31536000; includeSubDomains`, `docker/nginx/static-security-headers.conf`) fica.

### 4. Supervisor na Auditoria

- `Audit\Index`: rota sai do grupo `can:admin` para o `can:supervisor` em `routes/web.php`; `mount()` autoriza `supervisor`.
- `Navigation::items()`: o item "Auditoria" passa a ter o portão `supervisor`. O teste
  `test_toda_rota_da_lista_existe_e_o_portao_confere_com_o_middleware` precisa continuar verde.
- `x-audit-tabs` (`resources/views/components/audit-tabs.blade.php`): a aba "Sistema" só aparece para quem pode `admin`.
  *Decisão minha:* para o supervisor sobra uma aba só, então o `<nav>` inteiro some para ele.
- `tests/Feature/Livewire/AuditPageTest.php:43` (`test_so_admin_entra`) afirma 403 para o supervisor: reescrever (RED antes).
  A rota `auditoria.sistema` fica no grupo `can:admin` e não está no menu, então o `NavigationTest` segue valendo.
- `Audit\SystemLog` não muda (segue `admin`). Atendente continua sem acesso a nenhuma das duas.
- O que o supervisor vê na Auditoria de tickets: tudo, como o admin (o dono não pediu filtro por setor).

### 5. Dependabot

Só documentação (decisão registrada em `docs/security.md`) e limpeza da pendência.

### 6. PHPMD

- Sintaxe da 3.x: `maxmethods` → `maximum` (só existe essa, em `TooManyPublicMethods`; a antiga ainda funciona como alias,
  é higiene).
- Ligar o `cleancode.xml` **sem** `StaticAccess` (794 avisos: as facades do Laravel) e **sem** `BooleanArgumentFlag` (28),
  anotando o porquê no `phpmd.xml`. Corrigir os demais nesta leva: `ElseExpression` 9, `IfStatementAssignment` 8,
  `MissingImport` 2, `ErrorControlOperator` 1 (contagem de 25/11).
- Ligar o `design.xml` (`CouplingBetweenObjects`, limite padrão 13) com **baseline** (`--baseline`/`--generate-baseline` do
  PHPMD 3) contendo as classes acima do limite, menos `App\Models\Ticket` e `App\Models\Message`, que ganham
  o doc comment `@SuppressWarnings("PHPMD.CouplingBetweenObjects")` com o motivo escrito (o acoplamento vem dos tipos do próprio Eloquent:
  relações, `Builder`, Carbon, enums). O baseline é temporário: a leva seguinte refatora as 22 e apaga o arquivo.
  As contagens (24 no design, 22 no baseline) foram medidas em 25/11 com o PHPMD 3.0.0 instalado, que só reporta acima do
  `maximum`; regerar e conferir antes de gravar o baseline. O repositório usa o doc comment `@SuppressWarnings("PHPMD.X")`, e é ele que vale nos dois models: o atributo
  `PHPMD\Attribute\SuppressWarnings` importaria um pacote de require-dev em `app/` (o `ProductionDependenciesTest` reprova).
  O `UnusedSuppression` do `unusedcode.xml` confere o doc comment: se a supressão não suprimir nada, ele acusa.
- `naming.xml` fica de fora (238 dos 241 avisos são `ShortVariable` como `$q`, `$e`), anotado no `phpmd.xml`.
- O comando do CI, do `composer.json` (`complexity`) e do portão 7 no `CLAUDE.md` passam a usar o baseline.

### 7. PHPat

- Reprova o CI de verdade (decisão do dono). Violação pequena se corrige nesta leva; a que depender da refatoração das classes
  acopladas entra como exceção nomeada (uma por regra, com motivo), apagada pela leva seguinte.
- Regras iniciais (proposta minha a partir da estrutura atual; o builder confere com o código e reporta o que violaria antes de
  ligar):
  - `App\Models` não depende de `App\Livewire` nem de `App\Http`.
  - `App\Services` e `App\Actions` não dependem de `App\Livewire` nem de `App\Http`.
  - `App\Enums` e `App\Support` não dependem de `App\Livewire`, `App\Http` nem `App\Services`.
  - `App\Services\Waha\WahaClient` só é usado dentro de `App\Services`, `App\Actions`, `App\Jobs` e `App\Console`.
    Hoje há 4 usos fora: `app/Livewire/Settings/ChannelCard.php`, `app/Livewire/Inbox/Concerns/ComposesMessages.php`,
    `app/Livewire/Settings/Concerns/SendsTestMessage.php` e `app/Http/Controllers/MessageMediaController.php`. **Decisão do
    dono:** mover esses usos para serviços ou ações (existentes ou novos) nesta leva, com TDD, e a regra entra sem exceção.
  - Conferido pelo builder check: as regras 1 a 3 não têm violação hoje.

### 8. Trava de migração

- Script em `.github/scripts/` (Node, como o `npm-audit.mjs`), com teste em `tests/js/`.
- Olha só as migrations **adicionadas** no PR (diff contra a base) e só o corpo do `up()`.
- Reprova quando encontra `dropColumn`, `dropColumns`, `renameColumn`, `->change()` (com ou sem espaço), `Schema::drop`,
  `Schema::dropIfExists`, `Schema::rename`, `->drop(`, `->dropIfExists(`, `->rename(` (cobre `Schema::connection(...)`) e os
  helpers do Blueprint `dropConstrainedForeignId(For)`, `dropTimestamps(Tz)`, `dropSoftDeletes(Tz)`, `dropMorphs`,
  `dropNullableMorphs`, `dropRememberToken`. SQL cru em string não é analisado. `dropForeign` e `dropIndex` não contam (não quebram o código da versão anterior). Passa se a mesma
  instrução tiver, na linha anterior ou na mesma, um comentário `// contract: <motivo>` (motivo não vazio).
- *Decisão minha:* passo próprio dentro do job `static` (que já é check obrigatório da `main`; um job novo seria decorativo
  sem mexer no ruleset), com `if: github.event_name == 'pull_request'`. O checkout do `static` já tem `fetch-depth: 2`; no PR o
  HEAD é o merge commit, então `git diff --name-only --diff-filter=A HEAD^1 HEAD -- database/migrations` dá as adicionadas.
  Documentar a convenção em `docs/` junto do fluxo de migração.

### 9. Infection

Uma rodada local sobre `app/Services`, `app/Actions` e `app/Policies`. O PHP do Windows não tem driver de cobertura:
**decisão do dono**, rodar num container descartável PHP 8.5 com `pcov` (o mesmo driver do CI), montando o projeto e usando o
Postgres de teste local; o PHP do Windows não muda. O Infection entra só no container (`composer require` lá dentro ou o PHAR),
sem ir para o `composer.json`. Threads só se cada uma tiver o próprio banco (`TEST_TOKEN`); senão em série, registrando o
tempo. Relatório em
`docs/superpowers/reports/2026-11-25-infection.md` com o escore (MSI, covered MSI), tempo e os mutantes sobreviventes mais
relevantes.

## Restrições

- Fluxo do projeto: TDD em cada tarefa, implementadores um por vez, revisão final do branch pelo `revisor-final`, portão 7
  completo (inclui a suíte com Redis).
- Testes do coletor com Redis em mente: dedupe por TTL simulado com `Cache::forget`, nunca `travel()`.
- Mudança de tela (abas da Auditoria e menu) passa pelo `conferente-visual` nas três resoluções.
- CSP: a rota do coletor não pode ser bloqueada pela própria CSP (`connect-src 'self'` já cobre o mesmo domínio).

## Deixado para o builder

- Nomes de classes e métodos do coletor (controller invocável no padrão de `app/Http/Controllers`), número do limite por IP.
- Para onde vai cada um dos 4 usos do `WahaClient` (serviço ou ação existente, ou um novo), olhando o que já existe.
- Pequenas correções do cleancode: a forma de cada uma.

## Leva seguinte (já combinada)

Refatorar as 22 classes acima de 13 (`LiveEffects` 34, `RestoreDatabase` 33, `Inbox` 30, `BotEngine` 29, `IngestMessage` 27,
`Backups` 26, …), começando por cronometrar uma classe média (`StartConversation`, 19) para recalcular a estimativa (~35
tarefas, 18 a 26 h); apagar o baseline do PHPMD e as exceções do PHPat.
