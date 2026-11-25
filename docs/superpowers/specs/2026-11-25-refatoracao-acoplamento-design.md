# Refatoração do acoplamento (CBO > 13) — design

Data: 25/11/2026. Origem: pendência combinada depois do #159 (`phpmd.baseline.xml`, 22 classes com
`CouplingBetweenObjects` acima do limite padrão 13).

## Intenção

Levar as 22 classes do baseline a no máximo 13 dependências pelo método certo (cada responsabilidade na sua classe) e
apagar o `phpmd.baseline.xml` no fim. O dono quer o acoplamento corrigido de verdade agora, nada adiado, e sem afrouxar o
limite.

## Objetivos

- Cada uma das 22 classes sai do baseline com CBO ≤ 13, medido pelo PHPMD sem o baseline.
- Nenhuma classe nova ou tocada passa de 13 (o PHPMD com baseline já reprova isso para quem não está na lista).
- Comportamento igual: a suíte atual passa sem mudar o que ela afirma; só mudam testes que chamam o que mudou de lugar.
- No último PR o baseline some: o arquivo, o `--baseline-file` do `composer.json` (script `test`, linha 95), o comando do
  portão 7 no `CLAUDE.md` e o comentário da regra no `phpmd.xml`.

## Não objetivos

- Mudar comportamento, textos, HTML das telas ou o contrato público dos componentes Livewire (propriedades públicas,
  métodos chamados pela view, eventos `#[On]`/`dispatch`).
- Reduzir outras métricas ou refatorar classes fora da lista (as que só são tocadas porque chamavam algo que mudou de
  lugar recebem apenas o ajuste da chamada).
- Reescrever as traits `Concerns` que já existem e ficam abaixo de 13 se medidas como classe: `Inbox\Concerns\ActsOnMessages`
  (10 imports), `TakesNotes`, `Settings\Concerns\RecoversHistory`, `SendsTestMessage` e `Livewire\Concerns\ReceivesAvatar`.
  A `Inbox\Concerns\ComposesMessages` (16 imports, 383 linhas) é a exceção: entra na fatia Livewire (ver "Respostas do
  dono").

## Antiobjetivos (baixam o número sem baixar o acoplamento; reprovam na revisão)

Medido em 25/11 com o PHPMD 3.0.0 daqui: o CBO **conta** tipos de parâmetro, retorno e propriedade, `new`, chamada
estática (facades incluídas), `instanceof`, `catch`, `X::class`, `@param`/`@return`/`@var`/`@throws` simples de docblock e
atributos de método; **não conta** atributos de classe (`#[Title]`, `#[Layout]`), a classe pai, genéricos dentro de
docblock (`list<X>`) nem o código de uma trait usada pela classe. Por isso fica proibido:

- Mover código para trait (nova ou existente) para baixar o número: a trait some da métrica, o acoplamento continua.
- Apagar tipos: tirar type hint, trocar tipo por `mixed`/`object`, apagar `@return`/`@throws`/`@var` corretos, resolver
  classe por string (`app('...')`, nome montado) ou esconder tipo em genérico de docblock.
- Classe de passagem: uma classe que só repassa chamadas (fachada sem responsabilidade) para a original parecer menor.
  Ao dividir uma classe, os chamadores passam a usar as partes novas; a antiga some se ficar vazia.
- Dividir tela Livewire em subcomponentes (decisão do dono).

## Restrições

- Camadas do PHPat (`tests/Architecture/LayerRules.php`): classe extraída de tela vai para `App\Services` ou
  `App\Actions` e não recebe tipo de `App\Livewire` nem `App\Http` (ex.: o `ContactForm` fica na tela, a ação recebe o
  array/valores). Só `Services`, `Actions`, `Jobs` e `Console` usam o `WahaClient`.
- Supressão `@SuppressWarnings("PHPMD.CouplingBetweenObjects")` só por doc comment, com motivo na mesma linha (como em
  `Models\Ticket`), nunca pelo atributo do PHPMD (pacote de dev; reprova o `ProductionDependenciesTest`). Vale apenas para
  os casos da seção "Supressão".
- Implementadores um por vez (Postgres e Redis de teste compartilhados).
- Livewire: `wire:click`, `wire:model`, `$wire.` e `@this` não aparecem no CodeGraph; antes de mexer em método ou
  propriedade pública, buscar o nome em `resources/views`.

## Approaches

- **A — um PR por área, em quatro (escolhida pelo dono).** Bot, Inbox, Sistema, Livewire; cada PR tira as suas classes do
  baseline. Revisões do tamanho certo (5 a 11 classes), o baseline encolhe a cada merge e um problema numa área não segura
  as outras.
- **B — um PR só.** Menos cerimônia, mas a revisão final pega ~22 classes e dezenas de chamadores de uma vez, e nada
  chega à `main` até tudo acabar.
- Descartada: **22 PRs, um por classe.** Custo fixo de portões 6 a 8 multiplicado por 22, e classes da mesma área (ex.:
  `LiveEffects` usa `AlertDispatcher`, `Presence`, `SendTicketMessage`) se refatoram melhor juntas.

## Decisões do dono

1. Refatorar de verdade, sem afrouxar o limite.
2. Telas Livewire: consultas e regras saem para classes de consulta/ação; a tela fica com estado e ligação com a view; o HTML
   não muda; conferência visual só nas telas tocadas. Sem subcomponentes.
3. Sobra de framework: se, depois de extrair tudo que é regra/consulta, a tela continuar acima de 13 só por tipos do
   Livewire/Laravel (`Component`, `View`, atributos de método como `#[On]`/`#[Url]`/`#[Locked]`, `WithPagination`,
   `WithFileUploads`…), ela ganha a supressão com motivo. Sobrou dependência de domínio → continua refatorando.
4. Entrega pela abordagem A.
5. Cronometrar a primeira classe (`StartConversation`), mandar UMA linha ao dono com o tempo e a estimativa recalculada, e
   seguir sem parar.

## Ordem das fatias (minha decisão)

1. **Inbox** (abre com a `StartConversation`, a cronometrada): `StartConversation` 19, `AlertDispatcher` 15, `Presence` 14,
   `SendTicketMessage` 20, `IngestMessage` 27.
2. **Bot**: `LiveEffects` 34, `BotEngine` 29.
3. **Sistema**: `RestoreDatabase` 33, `ResetSystem` 22, `ApplyUpdate` 17, `Preflight` 15.
4. **Livewire**: `Inbox\Inbox` 30 (com a trait `ComposesMessages`), `Settings\Backups` 26, `Inbox\Transcript` 21, `Settings\System` 19,
   `Settings\ChannelCard` 16, `Automation\Editor` 16, `Contacts\ContactCard` 15, `Setup\FirstSteps` 14, `Settings\Users` 14,
   `Inbox\NewConversation` 14, `History\Index` 14.

Por quê: a cronometragem pede a `StartConversation` primeiro; a `LiveEffects` depende de três serviços da Inbox
(`AlertDispatcher`, `Presence`, `SendTicketMessage`), então a Inbox já reorganizada evita mexer duas vezes; as telas
dependem dos serviços e ficam por último. A ordem da lista do dono (Bot antes de Inbox) muda só na execução; os PRs
continuam um por área.

## Como cada classe sai do baseline (o ciclo)

- **RED:** apagar a linha da classe no `phpmd.baseline.xml` e rodar o PHPMD (com baseline): reprova com o CBO atual. Se a
  extração cria classe nova com lógica, o teste dela (que falha por a classe não existir) também é RED.
- **Rede antes de mover:** comportamento que a classe tem e nenhum teste cobre ganha teste de caracterização primeiro,
  passando no código atual (ex.: o CodeGraph não acha teste perto de `BotEngine::start`).
- **GREEN:** PHPMD passa, a classe medida sem baseline dá ≤ 13, a suíte e o PHPStan (com o PHPat) passam.
- **Efeito cascata:** dividir um serviço muda os chamadores (o `AlertDispatcher` tem 20). Medir sem baseline antes e
  depois de cada tarefa; uma classe ainda no baseline não pode subir sem registro no ledger, e nenhuma fora dele passa de 13.

Medir sem baseline: copiar o `phpmd.xml` para uma pasta sem o `phpmd.baseline.xml` ao lado e rodar
`vendor/bin/phpmd analyze app --format text --ruleset <copia>/phpmd.xml | grep CouplingBetween`.

## Direção por classe

Fatia 1 (detalhada; o plano fecha os nomes e as contas):

- **`StartConversation` (19):** a ação fica como orquestradora (guard, transação com o lock, ordem dos passos). Saem para
  classes próprias em `App\Services\Inbox`: o ritmo por canal/contato (`RateLimiter`, chaves, as duas constantes,
  reservar/devolver) e a confirmação de que o número tem WhatsApp (`WahaContacts`, `WahaClient`, `WahaRequestException`;
  hoje é o método público `assertHasWhatsApp`, que a `NewConversation` também chama e passa a chamar a classe nova). Se
  ainda passar de 13, a abertura do ticket com o evento e a primeira mensagem vira uma ação própria.
- **`AlertDispatcher` (15):** divide em alertas de canal (`channelChanged`, `confirmDown`, `numberChanged`, o `announceUp`
  privado) e alertas de ticket (`sla`, `assigned`, `queued`), ambos usando `AlertRecipients`. Os chamadores passam a usar a
  parte certa; o `AlertDispatcher` some.
- **`Presence` (14):** sai uma parte coesa, por exemplo a janela de `offline` (token, `GoOfflineJob`, `scheduleOffline`,
  `goOffline`, a chave).
- **`SendTicketMessage` (20) e `IngestMessage` (27):** separar a gravação (mensagem, eventos do ticket) dos efeitos
  depois do commit (jobs, eventos de broadcast, alertas, bot); o plano decide as divisões lendo as classes inteiras.

Fatias 2 a 4 (direção; o plano de cada fatia confirma):

- **`LiveEffects` (34):** os efeitos se agrupam por assunto (mensagens e mídia; fila, transferência e encerramento;
  contato, etiquetas e nota) em colaboradores; a `LiveEffects` continua implementando `BotEffects` e delega.
- **`BotEngine` (29):** a criação de sessão/evento e a montagem da execução ao vivo saem; o mapa `NodeType` → bloco sai
  do motor (ver "Supressão").
- **`RestoreDatabase` (33):** etapas (conferências, troca do banco, pós-restauração com avisos e recuperação de
  mensagens) em classes próprias.
- **`ResetSystem` (22):** desconexão do WAHA e limpeza de arquivos saem.
- **`ApplyUpdate` (17):** backup antes da atualização e limpeza do aviso de nova versão saem.
- **`Preflight` (15):** leitura do compose/YAML separada das checagens HTTP.
- **Telas:** consultas viram classes de consulta, regras viram ações (o `Inbox\Inbox` já usa várias de `App\Actions\Inbox`).

## Supressão

1. Tela Livewire com sobra só de framework (decisão 3).
2. O mapa `NodeType` → classe do bloco (decisão do dono, 25/11). São 13 blocos mais a base `Node`: qualquer classe que
   monte esse mapa tem 14 ou mais por construção (inclusive com `X::class`, que conta). O mapa vai para uma classe pequena
   só com ele, suprimida com motivo; o `BotEngine` fica ≤ 13 sem supressão.
3. Qualquer outra classe que não desça a 13 sem cair num antiobjetivo **não** ganha supressão por conta própria: fica no
   baseline, a fatia segue com as outras e o caso vai ao dono no fim da fatia, antes do PR, com o CBO alcançado e o motivo.

## Cronometragem

- Intervalo: do despacho do implementador da tarefa da `StartConversation` até a aprovação do `revisor-tarefa` dela, com
  hora de início e fim no ledger.
- Estimativa: (tempo ÷ 6 pontos de excesso da `StartConversation`) × 152 pontos de excesso restantes (soma de CBO − 13 das
  outras 21 classes), mais o custo fixo dos portões 6 a 8 por PR (4 PRs). Uma linha ao dono, e segue.

## Verificação por PR

- Portão 7 completo (Pint, PHPStan com PHPat, PHPMD com o baseline encolhido, `node --test tests/js`, `npm run build`,
  suíte com Redis).
- Tabela no ledger com o CBO de cada classe da fatia antes e depois, e das classes novas.
- Fatia Livewire: conferência visual das telas tocadas pelo `conferente-visual` nas três resoluções (720p, 1080p, 2K).
- Revisão do branch pelo `revisor-final` em cada PR.

## Decisões minhas (do builder check)

- **Um plano por fatia**, escrito no início de cada uma, lendo as classes já como a fatia anterior deixou. O plano da
  fatia 1 abre com a `StartConversation`; a estimativa usa os números desta spec.
- **Subida de CBO de classe ainda no baseline:** o baseline é por arquivo e não pega subida. Basta a medição manual sem
  baseline antes e depois de cada tarefa, registrada no ledger; sem teste automático (o baseline some no fim).

## Respostas do dono ao builder check (25/11)

- Supressão fora das telas: só o mapa dos blocos; o resto volta ao dono no fim da fatia (seção "Supressão").
- **PRs empilhados:** quando uma fatia passa no portão 7 e na revisão final, abro o PR dela (este é o pedido do dono para
  os 4 PRs). A fatia seguinte nasce de um branch criado a partir da anterior e começa sem parar. Merge só quando o dono
  nomear; depois do merge de uma fatia, a seguinte é rebaseada na `main`.
- **`ComposesMessages` entra:** medida como se fosse classe (copiar o conteúdo para uma classe de rascunho fora de `app/`
  e medir), desce a ≤ 13 extraindo consultas e regras, na fatia Livewire junto com o `Inbox\Inbox`.

## Fica para o construtor

Nomes das classes novas, onde cada método cai, a ordem das tarefas dentro da fatia e quantos testes de caracterização
cada classe pede, desde que dentro das regras acima.
