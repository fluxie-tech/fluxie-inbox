# WAHA: prazo da mídia, chats ignorados e recusa de ligações

Data: 24/11/2026. Branch `feat/waha-midia-e-ligacoes`.

## Intenção

O disco da VPS é a prioridade. No staging, o volume `waha-media` tinha 410 MB cerca de um dia depois do Zerar de 23/11,
contra 6,4 MB do `waha-sessions`. Hoje o WAHA nunca apaga os arquivos (`WHATSAPP_FILES_LIFETIME=0`), mesmo depois de o app
copiar cada um para o RustFS. Ele também recebe, baixa e grava as mensagens de grupos, status, canais e listas de transmissão,
que o app descarta. O dono também quer que os números ligados ao sistema não recebam ligações: o WAHA recusa a ligação e
responde com uma mensagem, e a chave e o texto são configurados dentro do app.

## Objetivos

1. O arquivo de mídia no WAHA dura 14 dias, incluindo o que já está acumulado.
2. O WAHA não gera evento, não baixa mídia e não grava no banco do GOWS as mensagens de status, grupos, canais e listas de
   transmissão.
3. Ligações recebidas por qualquer canal são recusadas na hora, e o cliente recebe a mensagem configurada.
4. Em Configurações > Canais, o admin liga ou desliga a recusa e edita o texto da mensagem.

## Não objetivos

- Limpar o banco do GOWS (`waha-sessions`). Fica para uma frente própria de pesquisa, porque não há forma suportada e as saídas
  (SQL direto no SQLite, mover o banco para o Postgres, trocar o WAHA) mudam a arquitetura. Em 24/11 o banco guardava uma
  mensagem de 07/07/2026: ele não se limpa sozinho.
- Prazo do texto das mensagens no nosso banco (decisão de LGPD em aberto no `docs/security.md`).
- Configuração por canal: a recusa e o texto valem para todos os canais.
- Registrar a tentativa de ligação no ticket.

## Anti-objetivos

- Reiniciar a sessão do WhatsApp para aplicar a configuração de ligações (o `PUT /api/sessions/{sessão}` derruba e religa a
  sessão).
- Usar os endpoints de apagar mensagem do WAHA para limpeza: eles apagam no próprio WhatsApp.

## Approaches

1. **Compose para o que é fixo, app para o que é editável (escolhida pelo dono).** O compose leva o prazo da mídia, as quatro
   regras de ignorar e a liberação do app de ligações do WAHA. O app registra o app de ligações em cada sessão pela API de
   apps do WAHA (`/api/apps`), que não reinicia a sessão. Custo: as instalações que já existem editam o compose no Coolify uma
   vez, o que o prazo da mídia já exigiria.
2. **Tudo pela configuração da sessão enviada pelo app** (`config.ignore` e `apps` no `PUT /api/sessions`). Uma fonte só, mas
   cada salvamento derruba e religa todos os canais, e as sessões já conectadas só pegam a regra de ignorar no próximo
   "Conectar".
3. **Tudo no compose.** Descartada: a chave e o texto não ficariam no app.

## Desenho

### Compose (`docker-compose.yml`, serviço `waha`)

- `WHATSAPP_FILES_LIFETIME: "1209600"` (14 dias). O WAHA, a cada partida, varre `WHATSAPP_FILES_FOLDER` e agenda a remoção de
  todo arquivo pela data de modificação (o que já passou do prazo sai em até 10 s), e cada arquivo novo é agendado ao ser
  gravado (`MediaLocalStorage::purge` e `save` no código do WAHA). Não precisa de rotina nossa.
- `WAHA_SESSION_CONFIG_IGNORE_STATUS`, `_GROUPS`, `_CHANNELS` e `_BROADCAST` em `"true"`. Valem para as sessões que não mandam
  `config.ignore` próprio; o `WahaClient::sessionConfig()` não manda, e continua não mandando.
- `WAHA_APPS_ENABLED: "True"` e `WAHA_APPS_ON: "calls"`.

### Mídia no app

- `PurgeMediaCommand` ganha uma constante com os 14 dias do WAHA (comentário apontando para o compose: o app não enxerga as
  variáveis do serviço `waha`). A mensagem com mídia, sem cópia no disco (`media_path` nulo), sem `media_purged_at` e com mais
  de 14 dias passa a ser marcada como expirada do mesmo jeito que a de 90 dias (`media_purged_at` e evento `media_purged`).
  Sem isso a tela oferece um anexo que o WAHA já apagou e o `MessageMediaController` devolve 404 só ao abrir.
- A mensagem de saída com anexo ainda na fila (sem `wa_message_id`) não pode ser marcada por essa regra se a cópia local ainda
  existe; a regra nova só pega `media_path` nulo.
- Comentário do `ResolveMessageMediaJob::copyToDisk` ("o WAHA só o mantém por alguns dias") passa a citar os 14 dias.
- Comentário do `RecoverMessages::MAX_WINDOW_DAYS` ("o GOWS guarda só alguns dias") está errado: corrigir para o motivo real
  do teto de 7 dias, ou só tirar a afirmação.
- A tela Anexos e retenção (`Retention`) não muda.

### Recusa de ligações

- **Configuração:** chaves no `settings` (modelo `Setting`), como `CompanySettings`: `calls.reject` (bool, padrão `true`) e
  `calls.message` (texto, padrão "Este número não recebe ligações. Envie sua mensagem em texto ou áudio que responderemos por
  aqui."). Uma classe de leitura no padrão de `App\Settings`.
- **Tela:** seção "Ligações" em Configurações > Canais (`App\Livewire\Settings\Channels`), só admin: chave "Recusar ligações" e
  o texto. Validação do texto: obrigatório com a chave ligada, tamanho máximo e sem caractere de controle (regra
  `NoControlChars`). Salvar grava auditoria (`Auditor::record`, ação nova).
- **Aplicação no WAHA:** uma classe que, para um canal, cria ou atualiza o app `calls` da sessão pela API de apps e o desliga
  quando a chave está desligada. Corpo documentado: `app`, `session`, `id`, `config.dm` e `config.group`, cada um com `reject`
  e `message`. Id do app estável por sessão (ex.: `app_{sessão}`). Roda:
  - ao salvar a seção, para todos os canais ativos (falha do WAHA não perde o que foi salvo: a tela avisa e a conferência
    diária aplica depois);
  - no `WahaClient::start()` (Conectar), depois de criar ou iniciar a sessão. **Decisão (Claude):** falha aqui não bloqueia o
    Conectar; vai para o log e a conferência diária corrige;
  - uma vez por dia no agendador, para os canais ativos, corrigindo o que estiver diferente (instalação atualizada, WAHA
    recriado). **Decisão (Claude):** sessão que não existe no WAHA (404) é pulada sem erro; outra falha vai para o log e não
    impede os demais canais.
- **Ligação em grupo (decisão de Claude):** também recusada (a intenção é desligar ligações), mas **sem mensagem**, para o
  texto não aparecer a todos os membros. Se a API não aceitar `message` vazio ou omitido em `group`, vai o mesmo texto do
  `dm`; a primeira tarefa confirma.
- Não toca no `PUT /api/sessions`.

### Docs

- `docs/security.md`, tabela "Retenção dos dados guardados": linha do arquivo de mídia no WAHA (`waha-media`), 14 dias, pelo
  próprio WAHA (`WHATSAPP_FILES_LIFETIME`); linha das mensagens de grupo/status/canal/transmissão, não recebidas nem gravadas.
- Nota de instalação/atualização: as instalações existentes (staging, production) editam o compose no Coolify com as variáveis
  novas e reiniciam o WAHA. No staging dentro do escopo; na production só com o OK do dono.

## Restrições

- Imagem fixada `devlikeapro/waha:gows-2026.10.1` (WAHA Core, engine GOWS).
- Nunca reiniciar sessão para aplicar a configuração de ligações.
- Toda chamada ao WAHA passa pelo `WahaClient` (chave de API e base URL do ambiente).

## Confirmar antes de planejar o resto (primeira tarefa do plano)

**Decisão (Claude) sobre a forma do plano:** a primeira tarefa é um portão manual, feito pelo controlador com o dono (não por
implementador): editar o compose do staging no Coolify, reiniciar o WAHA, ligar para o número de teste e mandar uma mensagem
num grupo. O plano já detalha o caminho provável das tarefas seguintes; o que depende do item 4 fica numa tarefa marcada para
ser ajustada depois do portão. Se algum item falhar de um jeito que só a Approach 2 (reiniciar sessão) resolveria, o trabalho
para e o dono decide: nada de `PUT /api/sessions` por conta própria.

No staging, com o número de teste e o OK do dono para as variáveis novas:

1. O app `calls` existe na imagem Core fixada e `POST /api/apps` com `WAHA_APPS_ENABLED=True` e `WAHA_APPS_ON=calls` aceita a
   configuração no formato da documentação (o Swagger da instância fica desligado), inclusive `group` sem mensagem.
2. Criar, alterar e desligar o app não reinicia a sessão (o status segue `WORKING`).
3. Uma ligação de teste é recusada e a mensagem chega ao cliente.
4. Como a mensagem automática chega ao app pelo webhook (`message.any` com `fromMe`): se vira mensagem na conversa do ticket,
   se abre ticket sozinha e com que origem. O plano decide o tratamento a partir disso.
5. Depois do reinício com as variáveis novas: o volume de mídia caiu (arquivos com mais de 14 dias saíram) e uma mensagem de
   grupo não gera webhook.

## Fica com o builder

- Nome das chaves, da classe de configuração, da ação de auditoria e do horário da conferência diária (perto das outras
  rotinas das 03:xx).
- Tamanho máximo do texto.
- Como a classe de aplicação descobre se o app já existe (`GET /api/apps?session=` ou `GET /api/sessions/{sessão}?expand=apps`).
