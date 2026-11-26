# Encerramento do atendimento: motivos editáveis, feriados importáveis e encerramento automático configurável

Data: 26/11/2026. Branch: `feat/encerramento-atendimento`. Um PR por parte, na ordem 1 → 2 → 3.

## Intenção

O dono quer três coisas no fim do atendimento:

- **Encerrar sem digitar à toa.** Hoje o painel Encerrar obriga um resumo em todo chamado.
- **Motivos que o admin controla.** Hoje os motivos estão fixos no código.
- **Encerramento por inatividade do jeito do mercado** (Zendesk, Chatwoot, Intercom, Blip). Hoje é fixo em 24 h corridas, sem aviso e sem mensagem ao cliente.

Para o tempo "só em horário comercial" valer, os feriados precisam estar cadastrados. Por isso entra um importador de feriados simples, sem depender de pacote nem de serviço de terceiros: o admin gera um JSON com qualquer IA gratuita, a partir de um prompt que a tela monta, e importa o arquivo.

## Partes, metas e anti-metas

### Parte 1: motivos de encerramento editáveis

**Metas**

1. **Tabela `close_reasons`.** Colunas: `name`, `requires_summary` (bool), `active` (bool) e `position` (int).
   - A migração cria a tabela e já insere os 3 motivos de hoje, na ordem: Resolvido, Sem resposta, Cliente pediu, todos com `requires_summary = false`.
   - O insert fica na migração, e não num seeder, porque o Zerar roda `migrate:fresh` sem seed. Instalação nova, Zerar e instalação atualizada precisam ter os motivos.
2. **`tickets.close_reason_id`.** Chave estrangeira para `close_reasons`, nula para encerramentos automáticos e para chamados antigos sem motivo.
   - A mesma migração preenche essa coluna a partir de `close_outcome`: `resolvido`, `sem_resposta` e `cliente_pediu` viram os 3 ids inseridos.
3. **Expansão sem contração neste PR.** `close_outcome` continua no banco nesta versão, para uma volta de imagem não quebrar. A trava de migração do #159 exige o marcador `// contract:` em operação destrutiva, e a remoção da coluna fica para uma versão seguinte (anotar em `pendencias-v1`).
   - O código novo para de ler e de gravar `close_outcome`.
   - O enum `CloseOutcome` sai, ou fica só onde a migração e a leitura de eventos antigos precisarem dele.
4. **Painel Encerrar** (`inbox.blade.php` e `TicketPanel`):
   - O menu lista os motivos ativos, por `position`.
   - O resumo é obrigatório só se o motivo escolhido tiver `requires_summary`. Fora isso é opcional. Quando preenchido, vale o mínimo de 3 e o máximo de 500 caracteres de hoje.
   - O texto de ajuda muda conforme o motivo.
   - A validação aceita só motivo ativo e existente.
5. **Tela nova no grupo "Atendimento" do menu** (junto de Setores e Horário e SLA), só admin, no grupo `can:admin` das rotas. Título "Encerramento", porque o grupo já se chama Atendimento. Seção "Motivos de encerramento". O admin pode:
   - adicionar um motivo;
   - renomear (o nome novo vale também no histórico, no filtro e na linha do tempo);
   - reordenar;
   - ligar e desligar "pede resumo";
   - desativar e reativar.
   - **Apagar** só é possível se nenhum chamado usou o motivo. Os já usados só se desativam.
   - **Nunca pode ficar sem motivo ativo:** desativar o último é recusado com mensagem.
   - O nome é obrigatório, tem no máximo 60 caracteres, é único sem diferenciar maiúsculas e minúsculas e não aceita caracteres de controle (há a regra `NoControlChars`).
6. **Histórico, filtro, linha do tempo e cartões** passam a ler o motivo da tabela.
   - Arquivos envolvidos: `HistorySearch`, `History\Index`, `TicketTimeline`, `TicketEventText`, as views `ticket-details` e `contact-card`, factories e os cenários de `database/seeders/Cenarios`.
   - O filtro do histórico lista todos os motivos, inclusive os desativados.
   - O parâmetro de URL `outcome` passa a ser o id do motivo. Um valor antigo (o slug) é ignorado sem erro.
7. **Eventos antigos.** `ticket_events.payload.outcome` guarda o slug antigo, e esses eventos continuam mostrando o nome. Dois caminhos possíveis:
   - a migração reescreve o payload para `close_reason_id`;
   - a leitura faz o mapa do slug para o id.

   Fica a critério do builder, com teste. Os eventos novos gravam `close_reason_id`.
8. **Selo do motivo:** cinza (`neutral`) para todos. Hoje "Resolvido" é verde. O `x-status-badge` recebe enum, então o motivo passa `tone="neutral"` com o nome no slot.
9. **Nome único sem diferenciar maiúsculas e minúsculas:** índice único em `lower(name)` no Postgres.

**Anti-metas**
- Motivo por setor.
- Motivo nos encerramentos automáticos: inatividade e Automação continuam sem motivo.
- Relatório novo por motivo.

### Parte 2: feriados com tipo, chave "a empresa fecha" e importador JSON

**Metas**

1. **`holidays` ganha duas colunas:**
   - `kind`: `nacional`, `estadual`, `municipal`, `facultativo` ou `manual`. O padrão é `manual` e é o valor dos que já existem.
   - `closes` (bool): o padrão é `true`.
2. **`BusinessHours::holidays()` considera só `closes = true`.** Com isso, o SLA, a resposta fora do horário, a Automação e o encerramento automático seguem a chave sem mudança em cada um.
3. **Na tela Horário e SLA:**
   - Cada feriado da lista mostra o tipo e a chave "a empresa fecha".
   - O "Adicionar feriado" de hoje continua e grava `kind = manual` e `closes = true`.
   - Os facultativos importados entram com `closes = false`.
4. **Importador, na mesma tela, numa seção "Importar feriados":**
   - **Montar o prompt:** campos Cidade, UF, De e Até, que não são gravados. O prompt se monta com eles e tem botão Copiar.
     - O prompt pede só JSON, no formato abaixo, com nacionais, estaduais e municipais da cidade e também os pontos facultativos, cada um com o tipo.
     - Pede ainda que a IA não invente: na dúvida sobre um municipal, deve deixá-lo de fora.
   - **Formato aceito:** só JSON, uma lista de objetos `{"data": "AAAA-MM-DD", "nome": "...", "tipo": "nacional|estadual|municipal|facultativo"}`.
     - Aceita também `DD/MM/AAAA` em `data`, porque a IA às vezes devolve assim.
     - Aceita o JSON dentro de um bloco de código Markdown, porque a IA costuma devolver assim.
     - Limite de 100 KB e de 500 itens.
   - **Prévia antes de gravar:** a lista lida, com data, dia da semana, nome e tipo, mais:
     - os itens com erro (data inválida, nome vazio ou com mais de 120 caracteres, tipo desconhecido), com o motivo;
     - as datas que já existem no cadastro, que serão puladas.
   - **Gravação:** nada é gravado até o admin clicar Importar.
     - Uma data já cadastrada fica como está, com o mesmo nome, o mesmo tipo e a mesma chave.
     - O tipo `facultativo` entra com `closes = false`; os outros, com `true`.
     - Tudo numa transação.
   - **Quem pode:** só o admin, como o resto da tela. O mesmo vale para a chave "a empresa fecha" e para apagar.
5. **Arquivo de Ribeirão Preto/SP, de 26/11/2026 a 31/12/2027, para uso pessoal do dono.**
   - Fica fora do repositório, em `.superpowers/`.
   - Ele é importado pela tela no staging. É o teste real do importador.
   - **Conteúdo:**

     | Tipo | Datas |
     |------|-------|
     | Nacional | 25/12/2026; 01/01, 26/03 (Sexta-feira Santa), 21/04, 01/05, 07/09, 12/10, 02/11, 15/11, 20/11 e 25/12/2027 |
     | Estadual SP | 09/07/2027 |
     | Municipal (Lei 11.919/2009) | 20/01 (São Sebastião), 27/05 (Corpus Christi, feriado municipal lá) e 19/06/2027 (aniversário da cidade) |
     | Facultativo | 24/12 e 31/12/2026 (à tarde); 08 e 09/02 (Carnaval), 10/02 (Cinzas), 24/12 e 31/12/2027 |

   - **Datas móveis de 2027**, pela Páscoa em 28/03: Sexta Santa em 26/03, Carnaval em 08 e 09/02 e Corpus Christi em 27/05.

**Anti-metas**
- Estado e Cidade no cadastro da empresa: o dono escolheu campos só para o prompt.
- Chamada a API ou download de base de feriados: fica para depois, anotado em `pendencias-v1`.
- Formato TXT.
- Meio expediente em facultativo: a chave vale o dia todo, e quem trabalha meio dia ajusta o horário.

### Parte 3: encerramento automático configurável

**Metas**

1. **Tela Encerramento** (a da Parte 1), seção "Encerramento automático", só admin, com uma regra para todos os setores. As configurações ficam em `settings`, numa classe `App\Settings\AutoCloseSettings` no molde das outras:
   - **Ligado/desligado.**
   - **Avisar depois de** X parado.
   - **Aviso ligado/desligado e texto do aviso.**
   - **Encerrar** Y depois do aviso sem resposta. Com o aviso desligado, encerra em X.
   - **Despedida ligada/desligada e texto da despedida.**
   - **Contar só o horário de atendimento** (ligado ou desligado).
   - Os textos aceitam `{{nome}}` (nome do contato) e `{{protocolo}}` e mostram uma prévia preenchida, como a resposta fora do horário.
   - **Tempo:** informado em horas e minutos, do mínimo de 15 minutos ao máximo de 30 dias. O intervalo Y depois do aviso vai de 15 minutos a 7 dias.
2. **Padrão, que também vale para instalação atualizada:** ligado, X = 24 h, aviso desligado, despedida desligada e contagem corrida. Nenhuma mensagem nova chega ao cliente até o admin mexer. Quais chamados fecham muda conforme a meta 3.
3. **Quem pode receber aviso e ser encerrado:**
   - Só chamado em `pendente` (com a Automação) ou `em_atendimento`.
   - **Chamado `aguardando` (na fila) nunca.**
   - Só quando a última mensagem que conta foi nossa, do atendente ou da Automação. **Respostas automáticas não contam como nossas:** a resposta fora do horário (`after_hours_reply`), o próprio aviso e a própria despedida. A recusa de ligação não vira mensagem (o eco é descartado em `HandleWahaEvent`) e fica fora.
   - Hoje `last_outbound_at` já ignora toda mensagem `MessageOrigin::BOT` (`OutboundStatus`, `PanelEcho`; teste `AfterHoursReplyTest::test_resposta_automatica_nao_conta_como_resposta_humana_nem_adia_o_encerramento`). Por isso ela não serve para "a última nossa" quando a Automação conta: a regra nova consulta `messages`. Como distinguir a resposta automática da mensagem da Automação (as duas são `BOT` hoje) fica com o builder, por exemplo uma coluna nova em `messages`. Precisa haver teste para cada tipo.
   - **Mudanças deliberadas em relação a hoje:**
     - `aguardando` com última saída humana nossa fechava em 24 h e passa a nunca fechar;
     - `pendente` só com mensagens da Automação nunca fechava e passa a entrar na regra.
4. **Contagem:**
   - O "parado" conta a partir da última mensagem que conta.
   - Com "só horário de atendimento", usa `BusinessHours::addBusinessSeconds`, que já existe. Sem horário cadastrado, conta o tempo corrido, como o `isOpen` de hoje.
   - **O aviso não zera o relógio.** O chamado guarda quando o aviso saiu, numa coluna nova, por exemplo `tickets.auto_close_warned_at`. O Y conta dessa marca e obedece à mesma chave "só horário" que X.
   - **Se o cliente responde depois do aviso,** a última mensagem passa a ser dele e não há encerramento. A marca só vale se for posterior à última mensagem nossa que conta, então não precisa ser limpa.
   - **O eco do aviso e da despedida não pode zerar o relógio:** se `StoreInbound` não adotar o eco, ele chama `recordOutbound`. Precisa de teste.
   - **Chamado com a Automação** (decisão do dono): enquanto houver `BotSession` ativa esperando com prazo (`expires_at`), a regra não age. O prazo do passo vence e o `ExpireBotSessionsCommand` manda para a fila, como hoje. A regra só vale para `pendente` sem esse prazo.
   - Se o cliente responde ao aviso, a Automação trata a resposta como resposta à pergunta pendente. Se não servir, repete a pergunta. Esse comportamento é aceito.
   - **Horário de envio** (decisão do dono): o aviso e a despedida só saem dentro do horário de atendimento (`BusinessHours::isOpen`), qualquer que seja o modo de contagem. Se vencer fora do horário, a mensagem sai na primeira rodada depois da abertura.
     - Sem horário cadastrado, sai a qualquer hora.
     - O encerramento em si espera a despedida quando ela está ligada: fecha e manda juntos, na abertura.
     - Com a despedida desligada, fecha quando vencer.
5. **Rotina:** estende `app:close-inactive-tickets`.
   - Passa a rodar a cada minuto (hoje é a cada 10), com `withoutOverlapping`.
   - Filtra no banco pelo tempo corrido. Como o tempo de expediente nunca passa do corrido, esse filtro é seguro como pré-filtro. Depois confere em PHP o tempo de expediente.
   - **Envio do aviso e da despedida:**
     - pelo caminho de hoje (`OutgoingMessages::create` e `SendTicketMessageJob`), sem a assinatura do atendente;
     - fora da transação e do lock que encerra;
     - a marca do aviso é gravada de forma idempotente, para uma rodada repetida não mandar dois avisos.
   - **Canal desconectado ou inativo:** sem aviso. O encerramento segue, e a despedida não é enviada.
   - **Limite por rodada:** no máximo 30 avisos e 30 despedidas por minuto (constante). O número é WhatsApp não oficial, e baixar X de repente avisaria todos os parados de uma vez. O resto fica para as rodadas seguintes.
   - **O encerramento usa a ação `CloseTicket`,** com `ClosedReason::INATIVIDADE`, no lugar do `update` em lote: só ela fecha a `BotSession`.
     - Antes de fechar, reconfere as condições com a linha travada e com o `Cache::lock('bot-ticket-'.$id)`, como o `ExpireBotSessionsCommand`, porque o cliente pode ter acabado de responder.
     - Primeiro fecha, depois manda a despedida.
   - O agendamento em `routes/console.php` passa de `everyTenMinutes()` para `everyMinute()`. O teste `test_esta_agendado_a_cada_dez_minutos` muda e a opção `--hours` sai.
   - Os testes usam as migrações reais, então `close_reasons` já nasce com 3 linhas: factories e contagens precisam considerar isso.
6. **Desligado:** nada é avisado nem encerrado.

**Anti-metas**
- Regra por setor.
- Regra separada para a Automação.
- Encerrar chamado na fila.

## Approaches consideradas

**Motivos**

| Opção | Escolha |
|-------|---------|
| **Tabela própria com FK** | **Escolhida.** O banco garante a ligação, renomear vale no histórico e o filtro consulta direto. |
| Lista JSON em `settings` com slug no chamado | Descartada: a migração é menor, mas não há integridade, e o histórico e o filtro procurariam o nome na lista. |

**Rotina**

| Opção | Escolha |
|-------|---------|
| **Estender a rotina atual** (a cada minuto, lendo `settings`) | **Escolhida.** Mudar a configuração vale na rodada seguinte, sem jobs velhos na fila. |
| Um job atrasado por chamado | Descartada: cada mudança de configuração ou de horário deixaria jobs velhos para conferir ou cancelar. |

**Fonte de feriados**

| Opção | Escolha |
|-------|---------|
| Base aberta embutida | Considerada. A base pesquisada é MIT e cobre 3.837 municípios em 2026, mas só tem dados até 2026. |
| Botão que baixa a base | Considerada: chamada a terceiro. |
| API com token | Considerada: o interior só nos planos pagos. |
| Seed de Ribeirão Preto | Considerada. |
| **Importador JSON com prompt para IA** | **Escolhida pelo dono.** Nenhuma dependência, a prévia protege contra erro da IA e serve a qualquer cidade. |

## Restrições

- Fluxo dos portões do `CLAUDE.md`: TDD, um implementador por vez, revisão final e portão 7.
- PHPMD sem baseline: acoplamento de no máximo 13 por classe. A tela nova não deve virar outra `Inbox` de 30. As regras e as consultas ficam em classes de ação e de consulta, como na refatoração do #160 ao #163.
- **Migrações só aditivas** nesta versão, por causa da trava do #159.
- UI com componentes Flux free, já usados no projeto. Conferência visual em 720p, 1080p e 2K nas telas tocadas: Encerrar, Atendimento, Horário e SLA e Histórico.
- Nenhuma chamada a serviço externo.

## A critério do builder

- Como distinguir a resposta automática da mensagem da Automação: nova origem, evento ou coluna.
- Reescrever o payload dos eventos antigos ou mapear na leitura.
- O nome da coluna da marca do aviso.
- O texto padrão do aviso e da despedida, em português, curto, com `{{nome}}`.
- O texto exato do prompt da IA.
- A divisão em tarefas do plano.

## Decisões minhas (para o dono conferir)

- A tela nova se chama "Encerramento" e fica no grupo Atendimento do menu.
- Selo de motivo todo cinza.
- Apagar motivo só se nunca foi usado.
- Limite de 30 envios por minuto.
- Tempo de 15 minutos a 30 dias.
- A rotina passa a rodar a cada minuto.
- Canal desconectado: encerra sem despedida.
- Estado e Cidade da empresa adiados. O dono pediu esses campos no começo e depois escolheu campos só para o prompt.
