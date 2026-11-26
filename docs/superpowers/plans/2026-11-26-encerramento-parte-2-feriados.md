# Encerramento, parte 2: feriados com tipo, chave "a empresa fecha" e importador JSON

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** cada feriado ganha tipo e a chave "a empresa fecha", e o admin importa feriados de um JSON que ele gera com qualquer IA a partir de um prompt montado pela tela, conferindo uma prévia antes de gravar.

**Architecture:**
- **Banco:** `holidays` ganha `kind` (enum `HolidayKind`) e `closes` (bool). `BusinessHours::holidays()` passa a considerar só `closes = true`; por isso o SLA, a resposta fora do horário, a Automação e (na parte 3) o encerramento automático seguem a chave sem mudança em cada um.
- **Tela:** a seção de feriados sai de `BusinessHoursPage` para um componente filho, `Settings\Holidays`.
- **Lógica:** a leitura do arquivo fica em `App\Services\Holidays\HolidayImportParser`, a gravação em `App\Actions\Holidays\ImportHolidays` e o texto do prompt em `App\Services\Holidays\HolidayPrompt`.

**Tech Stack:** Laravel 13, Livewire 4.4 (`WithFileUploads`), Flux 2 (free), PostgreSQL 18, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-26-encerramento-atendimento-design.md` (Parte 2).

## Global Constraints

- **Migração só aditiva**, por causa da trava `tests/js/migration-guard.test.mjs`. `kind` é `string(20)`, não nulo, padrão `'manual'`. `closes` é `boolean`, não nulo, padrão `true`. Os feriados que já existem ficam `manual`/`true`.
- **`HolidayKind`** (string-backed) tem os casos `NACIONAL='nacional'`, `ESTADUAL='estadual'`, `MUNICIPAL='municipal'`, `FACULTATIVO='facultativo'` e `MANUAL='manual'`.
  - `label()`: Nacional, Estadual, Municipal, Facultativo, Cadastrado à mão.
  - `tone()` para o `x-status-badge`: `neutral` em todos, menos `facultativo`, que é `warn`.
- **Importação:**
  - só JSON;
  - arquivo até 100 KB e no máximo 500 itens;
  - cada item é `{"data": "AAAA-MM-DD" ou "DD/MM/AAAA", "nome": string 1..120 (trim, NoControlChars), "tipo": "nacional"|"estadual"|"municipal"|"facultativo"}`;
  - aceita o JSON cercado por um bloco Markdown (` ```json … ``` ` ou ` ``` … ``` `);
  - `facultativo` entra com `closes = false` e os outros tipos com `closes = true`;
  - data já cadastrada é pulada sem nenhuma alteração;
  - data repetida dentro do próprio arquivo: vale a primeira, e as outras viram erro "Data repetida no arquivo.";
  - tudo é gravado numa transação.
- **Nada é gravado antes do clique em "Importar".** Ao importar, o servidor lê o arquivo de novo; ele nunca confia na prévia que veio do navegador.
- **Campos do prompt** (Cidade, UF, De, Até) não são gravados em lugar nenhum.
- **Permissão:** só admin, porque a rota já é do grupo `can:admin`. Mesmo assim, cada ação do componente chama `authorize('admin')`.
- **Acoplamento (PHPMD):** no máximo 13 por classe, sem baseline. Supressão só por doc comment com motivo, e só para sobra de framework.
- **Textos em português.** Os commits também, com prefixo e as linhas de atribuição.
- **Testes:**
  - o Postgres de teste roda na porta 5433 (`DB_PORT=5433`) enquanto a 5432 estiver ocupada pelo `omnigraph-db-1`;
  - testes com `DatabaseTruncation` já preservam `close_reasons`;
  - `holidays` não é semeada.
- **Desvio (dono, 26/11):** a conferência visual no navegador fica para o fim, no staging, depois da pré-release com as três partes. Neste branch valem a suíte, as revisões e o portão 7.

## Review Focus

1. **A IA devolve o JSON com texto antes e depois** ("Aqui estão os feriados: ```json [...] ``` Espero ter ajudado"): a leitura acha o bloco e usa só o que está dentro. Teste na Tarefa 3.
2. **Arquivo que não é JSON, JSON que não é lista, lista vazia ou mais de 500 itens:** uma mensagem só ("O arquivo não é uma lista JSON de feriados." ou "O arquivo passa de 500 feriados."), sem prévia e sem gravar nada. Teste na Tarefa 3.
3. **Data inexistente** ("2027-02-30", "31/04/2027"): vira erro da linha, com o motivo "Data inválida.", e o resto importa. Teste na Tarefa 3.
4. **Feriado facultativo importado não fecha o atendimento** até o admin ligar a chave: `BusinessHours::isOpen` continua aberto nesse dia. Teste na Tarefa 1, pelo `closes`, e na Tarefa 3, pelo tipo.
5. **Desligar "a empresa fecha"** num feriado já cadastrado reabre o atendimento naquele dia no `isOpen`, no `nextOpening` e no `addBusinessSeconds`. Teste na Tarefa 1.

---

### Task 1: tipo e chave "a empresa fecha" no feriado

**Files:**
- Create: `database/migrations/2026_11_26_000002_add_kind_and_closes_to_holidays.php`
- Create: `app/Enums/HolidayKind.php`
- Modify: `app/Models/Holiday.php` (casts `kind => HolidayKind`, `closes => boolean`; `#[Fillable]` com `kind` e `closes`; `@property`)
- Modify: `app/Services/Inbox/BusinessHours.php` (`holidays()` com `where('closes', true)`)
- Modify: `tests/Unit/EnumToneTest.php` (se ele percorre os enums com `tone()`, incluir `HolidayKind`)
- Test: `tests/Feature/Inbox/BusinessHoursTest.php`

**Interfaces:**
- Produces:
  - `App\Enums\HolidayKind` com `label(): string` e `tone(): string`.
  - `Holiday::$kind` (`HolidayKind`) e `Holiday::$closes` (`bool`).

- [ ] **Step 1: Testes que falham**

```php
public function test_feriado_que_a_empresa_nao_fecha_nao_fecha_o_atendimento(): void
// faixa seg 08–18; Holiday 2027-01-20 (quarta) closes=false => isOpen(2027-01-20 10:00 local) true; com closes=true => false

public function test_desligar_o_feriado_reabre_o_dia_no_proximo_horario_e_no_prazo_de_expediente(): void
// closes=false: nextOpening(2027-01-19 19:00) = 2027-01-20 08:00; addBusinessSeconds(2027-01-19 17:00, 7200) termina 2027-01-20 09:00

public function test_feriado_existente_vira_manual_e_fecha(): void
// migrate:rollback --path da migração nova; cria feriado pelo DB::table; migrate --path => kind 'manual', closes true
```

- [ ] **Step 2: Rodar e ver falhar.** Comando: `DB_PORT=5433 php artisan test --filter=BusinessHoursTest`. Esperado: FAIL.
- [ ] **Step 3: Implementar** a migração, o enum, o model e o filtro de `holidays()`.
- [ ] **Step 4: Rodar e ver passar.** Comando: `DB_PORT=5433 php artisan test --filter='BusinessHoursTest|BusinessSecondsTest|AfterHoursReplyTest|EnumToneTest'`. Esperado: PASS.
- [ ] **Step 5: Commit.** Mensagem: `feat: feriado com tipo e chave "a empresa fecha"`.

### Task 2: componente `Settings\Holidays` com a lista, o cadastro à mão e a chave

**Files:**
- Create:
  - `app/Livewire/Settings/Holidays.php`
  - `resources/views/livewire/settings/holidays.blade.php`
- Modify:
  - `app/Livewire/Settings/BusinessHoursPage.php`: tirar `holidayDate`, `holidayName`, `addHoliday`, `removeHoliday` e `holidays` do `render`, junto com o import de `Holiday`.
  - `resources/views/livewire/settings/business-hours-page.blade.php`: o painel "Feriados" (linhas ~44–70) passa a ser `<livewire:settings.holidays />`.
- Test:
  - `tests/Feature/Livewire/HolidaysTest.php` (novo)
  - `tests/Feature/Livewire/BusinessHoursPageTest.php`: os testes de feriado se mudam para o arquivo novo.

**Interfaces:**
- Consumes: `HolidayKind` e `Holiday::$closes` (Tarefa 1).
- Produces: `Settings\Holidays` com:
  - `public string $holidayDate = ''` e `public string $holidayName = ''`;
  - `addHoliday(): void`: mesmas regras e mensagens de hoje; grava `kind = manual` e `closes = true`;
  - `removeHoliday(int $id): void`;
  - `setCloses(int $id, bool $closes): void`;
  - todos com `authorize('admin')`, e `mount()` também.
  - O `render` passa `holidays` em `orderBy('date')`.

- [ ] **Step 1: Testes que falham**

```php
public function test_cadastra_e_remove_feriado_com_data_unica(): void   // movido de BusinessHoursPageTest; confere kind manual e closes true
public function test_liga_e_desliga_a_empresa_fecha(): void             // setCloses(id, false) => closes false; true => true
public function test_lista_mostra_tipo_e_chave(): void                   // assertSee label do tipo e o interruptor "A empresa fecha" de cada feriado
public function test_quem_nao_e_admin_nao_mexe_nem_forjando_a_chamada(): void // supervisor: cada ação lança AuthorizationException e nada muda (molde do ClosingPageTest)
public function test_a_pagina_horario_mostra_o_componente_de_feriados(): void // GET configuracoes.horario contém o painel "Feriados"
```

- [ ] **Step 2: Rodar e ver falhar.** Comando: `DB_PORT=5433 php artisan test --filter='HolidaysTest|BusinessHoursPageTest'`. Esperado: FAIL.
- [ ] **Step 3: Implementar.**
  - Lista: data `d/m/Y` e dia da semana abreviado (`seg`, `ter`, …, no fuso de exibição), nome, `<x-status-badge :status="$holiday->kind" />`, `flux:switch` "A empresa fecha" com `wire:click="setCloses({{ $holiday->id }}, {{ $holiday->closes ? 'false' : 'true' }})"` e o botão de remover de hoje (`data-confirm`).
  - Subtítulo do painel: "Nas datas em que a empresa fecha o atendimento fica fechado o dia todo."
- [ ] **Step 4: Rodar e ver passar.** Mesmo comando do Step 2. Esperado: PASS.
- [ ] **Step 5: Commit.** Mensagem: `feat: feriados num componente próprio com tipo e chave "a empresa fecha"`.

### Task 3: leitura do arquivo e gravação da importação

**Files:**
- Create:
  - `app/Services/Holidays/HolidayImportParser.php`
  - `app/Services/Holidays/HolidayImportRow.php` (`final readonly`)
  - `app/Actions/Holidays/ImportHolidays.php`
- Test:
  - `tests/Unit/Holidays/HolidayImportParserTest.php`: sem banco. As datas existentes entram por parâmetro.
  - `tests/Feature/Holidays/ImportHolidaysTest.php`

**Interfaces:**
- Produces:
  - **`HolidayImportRow`:** `__construct(public ?string $date, public string $name, public ?HolidayKind $kind, public ?string $error, public bool $exists)`.
    - `date` é `Y-m-d` ou `null` quando a data é inválida.
    - `error` é `null` numa linha boa.
    - `exists` vale `true` quando a data já está no cadastro.
  - **`HolidayImportParser::parse(string $contents, array $existingDates): array`**
    - Devolve `['error' => ?string, 'rows' => list<HolidayImportRow>]`.
    - `$existingDates` é `list<string>`, com datas `Y-m-d`.
    - Erro do arquivo (não é lista JSON, lista vazia, mais de 500 itens) volta em `error`, com `rows` vazio.
    - **Mensagens das linhas:**
      - `Data inválida.`
      - `Informe o nome do feriado.`
      - `O nome passa de 120 caracteres.`
      - `O nome tem caracteres que não podem ser usados.`
      - `Tipo desconhecido (use nacional, estadual, municipal ou facultativo).`
      - `Data repetida no arquivo.`
    - Aceita `data`, `nome` e `tipo`, e também `date`, `name` e `type`, que a IA às vezes usa em inglês.
  - **`ImportHolidays::__invoke(array $rows): int`**
    - Recebe `list<HolidayImportRow>`.
    - Grava só as linhas sem erro e sem `exists`, numa transação.
    - Antes de inserir, confere de novo no banco se a data já existe, dentro da transação, para cobrir uma data cadastrada entre a prévia e a gravação.
    - Facultativo entra com `closes = false`.
    - Devolve quantas linhas gravou.

- [ ] **Step 1: Testes que falham**

```php
// HolidayImportParserTest
public function test_le_a_lista_com_as_duas_formas_de_data_e_os_tipos(): void
public function test_acha_o_json_dentro_do_bloco_markdown_com_texto_em_volta(): void
public function test_arquivo_que_nao_e_lista_vazio_ou_grande_demais_da_um_erro_so(): void // 'nao json', '{"a":1}', '[]', 501 itens
public function test_data_inexistente_nome_vazio_ou_longo_e_tipo_desconhecido_viram_erro_da_linha(): void // '2027-02-30', '31/04/2027'
public function test_data_repetida_no_arquivo_vale_a_primeira(): void
public function test_data_ja_cadastrada_fica_marcada_como_existente(): void
public function test_aceita_as_chaves_em_ingles(): void

// ImportHolidaysTest
public function test_grava_so_as_linhas_boas_e_novas_e_facultativo_nao_fecha(): void
public function test_data_cadastrada_entre_a_previa_e_a_gravacao_e_pulada(): void
public function test_feriado_facultativo_importado_nao_fecha_o_atendimento(): void // BusinessHours::isOpen no dia do facultativo continua true
```

- [ ] **Step 2: Rodar e ver falhar.** Comando: `DB_PORT=5433 php artisan test --filter='HolidayImportParserTest|ImportHolidaysTest'`. Esperado: FAIL.
- [ ] **Step 3: Implementar** as assinaturas acima.
  - O bloco Markdown sai com a regex ``/```(?:json)?\s*(.*?)```/s``. Sem bloco, procura do primeiro `[` ao último `]`.
  - A data é validada com `CarbonImmutable::createFromFormat` mais a conferência de que a volta ao texto é igual, para recusar 30/02.
- [ ] **Step 4: Rodar e ver passar.** Mesmo comando do Step 2. Esperado: PASS.
- [ ] **Step 5: Commit.** Mensagem: `feat: leitura e gravação da importação de feriados em JSON`.

### Task 4: importador na tela, com prompt para a IA e prévia

**Files:**
- Create: `app/Services/Holidays/HolidayPrompt.php`
- Modify:
  - `app/Livewire/Settings/Holidays.php`
  - `resources/views/livewire/settings/holidays.blade.php`
- Test:
  - `tests/Unit/Holidays/HolidayPromptTest.php`
  - `tests/Feature/Livewire/HolidaysTest.php`

**Interfaces:**
- Consumes: `HolidayImportParser::parse`, `ImportHolidays` e `HolidayImportRow` (Tarefa 3).
- Produces:
  - **`HolidayPrompt::for(string $city, string $uf, string $from, string $to): string`**
    - `$from` e `$to` são `Y-m-d`. No texto aparecem como `d/m/Y`.
    - Texto exato, com os valores trocados:

```
Liste todos os feriados e pontos facultativos de {cidade}/{UF}, Brasil, entre {de} e {até}: os nacionais, os estaduais de {UF} e os municipais de {cidade}, mais os pontos facultativos nacionais (Carnaval, Quarta-feira de Cinzas, Corpus Christi quando não for feriado na cidade, véspera de Natal e de Ano-Novo).
Responda só com um JSON, sem nenhum texto antes ou depois, neste formato:
[{"data": "AAAA-MM-DD", "nome": "Nome do feriado", "tipo": "nacional"}]
O campo "tipo" é um destes: nacional, estadual, municipal, facultativo.
Não invente: se não tiver certeza de um feriado municipal, deixe-o de fora.
```

  - **`Settings\Holidays`**, com `use WithFileUploads`, ganha:
    - os campos do prompt: `public string $promptCity = ''`, `public string $promptUf = ''`, `public string $promptFrom = ''` e `public string $promptTo = ''`;
    - `public $importFile` (o arquivo enviado);
    - `public ?string $importError = null` e `public ?int $imported = null`.
  - **`updatedImportFile(HolidayImportParser $parser): void`:** valida `importFile` com `file`, `max:100` e `extensions:json` (mensagens "Envie um arquivo .json de até 100 KB."), lê o conteúdo e monta a prévia (ver o Step 3).
  - **`import(HolidayImportParser $parser, ImportHolidays $import): void`:**
    - Lê o arquivo de novo e chama `$import(rows)`.
    - Grava em `imported` quantas linhas entraram e limpa o arquivo e a prévia.
    - Mostra "{n} feriados importados." ou "Nenhum feriado novo para importar.".
  - **`cancelImport(): void`:** limpa o arquivo e a prévia.
  - Todos com `authorize('admin')`.

- [ ] **Step 1: Testes que falham**

```php
// HolidayPromptTest
public function test_monta_o_prompt_com_cidade_uf_e_periodo(): void // for('Ribeirão Preto','SP','2026-11-26','2027-12-31') contém 'Ribeirão Preto/SP', 'entre 26/11/2026 e 31/12/2027', 'estaduais de SP', '"tipo": "nacional"'

// HolidaysTest
public function test_o_prompt_so_aparece_com_os_quatro_campos_preenchidos(): void
public function test_enviar_o_arquivo_mostra_a_previa_sem_gravar(): void   // UploadedFile::fake()->createWithContent('f.json', ...) => assertSee nome, 'Já cadastrado', erro de linha; Holiday::count() inalterado
public function test_importar_grava_e_avisa_quantos(): void                // => '2 feriados importados.'; facultativo closes false
public function test_arquivo_invalido_mostra_um_erro_e_nao_mostra_importar(): void
public function test_arquivo_grande_ou_de_outro_tipo_e_recusado(): void   // 101 KB; 'f.txt'
public function test_cancelar_limpa_a_previa(): void
public function test_importar_e_admin_somente(): void                     // supervisor forjando import()/updatedImportFile => AuthorizationException
```

- [ ] **Step 2: Rodar e ver falhar.** Comando: `DB_PORT=5433 php artisan test --filter='HolidayPromptTest|HolidaysTest'`. Esperado: FAIL.
- [ ] **Step 3: Implementar.**
  - **Seção "Importar feriados"** no painel:
    - **Passo 1:** os quatro campos, Cidade (`maxlength=80`), UF (`maxlength=2`, guardada em maiúscula), De e Até (`type=date`). Com os quatro preenchidos aparece o prompt num `flux:textarea` somente leitura, com botão "Copiar". Antes de criar um componente Alpine, conferir em `vendor/livewire/flux` se o `flux:textarea`/`flux:input` livre tem `copyable`. Se não tiver, seguir o padrão de cópia de `resources/js/auth-ui.js`, respeitando o subconjunto CSP do Alpine (`tests/Unit/AlpineCspSubsetTest.php`).
    - **Passo 2:** o texto "Cole o prompt em qualquer IA gratuita, salve a resposta como arquivo .json e envie aqui." e o `flux:input type="file" accept=".json,application/json"` em `wire:model="importFile"`.
    - **Passo 3 (prévia):** uma tabela com Data (`d/m/Y` e dia da semana), Nome, Tipo (selo) e Situação ("Novo", "Já cadastrado" ou a mensagem de erro em `text-danger`).
      - Acima da tabela vai o resumo "{novos} novos, {existentes} já cadastrados, {erros} com erro".
      - Embaixo, os botões "Importar {novos} feriados" (escondido quando `novos = 0`) e "Cancelar".
  - **Prévia no servidor:**
    - Guarda só o necessário para mostrar a tabela num `#[Locked] public array $preview`.
    - Ao importar, tudo é lido de novo do arquivo.
- [ ] **Step 4: Rodar e ver passar.** Mesmo comando do Step 2. Esperado: PASS.
- [ ] **Step 5: Portão parcial.** Comandos: `vendor/bin/pint --parallel --test --cache-file=.pint.cache`, `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml`, `node --test tests/js` e `npm run build`. Esperado: código de saída 0 em todos.
- [ ] **Step 6: Commit.** Mensagem: `feat: importador de feriados em JSON com prompt para IA e prévia`.

---

Depois das tarefas vêm a revisão do branch (`revisor-final`) e o portão 7 completo. A conferência visual fica para o staging, por desvio do dono. O arquivo de Ribeirão Preto/SP para o teste real fica em `.superpowers/feriados-ribeirao-preto-2026-2027.json`, fora do repositório.
