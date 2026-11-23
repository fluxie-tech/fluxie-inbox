# Zerar sistema — design

Data: 23/11/2026. Branch: `feat/zerar-sistema`.

## Intenção

O dono conectou o número pessoal dele ao staging para testes com o Claude in Chrome e quer recomeçar do zero com o número
de teste. Em vez de uma limpeza manual pelo Coolify, ele pediu um botão no app que zere a instalação e a devolva ao primeiro
acesso (`/instalar` com o código do Coolify, depois `/primeiros-passos`). A primeira vez que o botão roda é a limpeza do staging,
que serve também como prova de aceite. Depois, qualquer instalação que precise recomeçar pode usar o botão, se a variável
estiver ligada.

O motivo de fundo é **privacidade**: conversas pessoais não podem sobrar em lugar nenhum da instalação.

## Metas

- Um admin, em **Configurações > Sistema**, apaga tudo o que a instalação guarda:
  - o banco inteiro: usuários, contatos, conversas, fluxos, configurações, token do Coolify e auditoria;
  - os bancos guardados por restaurações (`<db>_antes_*` e `<db>_restaurando_*`);
  - todas as mídias (bucket `media`);
  - **todos os backups** (bucket `backups`, com `restauracao/` incluído);
  - as sessões do WhatsApp no WAHA, com logout antes do delete, para o aparelho sair da lista de conectados.
- No fim, o sistema volta ao estado de instalação nova: sem admin, então `/instalar` pede o código do Coolify.
- O dono vê o andamento passo a passo e fica sabendo se algo falhou.

## Fora do escopo

- Apagar volumes ou recursos do Coolify. O app limpa o que é dele; a infraestrutura fica como está.
- Celular ou app (o dono tirou do escopo da conferência).
- Zerar só uma parte (por exemplo só as conversas).
- Desfazer. Não tem volta, de propósito.

## Antimetas (falhou mesmo "funcionando")

- Sobrar dado do número pessoal em qualquer lugar que o app controla: banco ativo, banco guardado, mídia, backup ou sessão do WAHA.
- O sistema ficar no ar recebendo mensagens no meio da limpeza e gravar conversa nova no banco recém-zerado.
- Um admin desatento zerar uma operação real: a variável vem desligada e a confirmação é forte.
- A instalação ficar presa em manutenção ou num estado em que nem `/instalar` nem o login funcionem.
- Apagar sessões do WAHA que não são desta instalação (um WAHA compartilhado apontado por `WAHA_URL`).

## Restrições

- **Variável `FLUXIE_ALLOW_RESET`**: no `docker-compose.yml`, dentro de `x-app-env`, com
  `FLUXIE_ALLOW_RESET: ${FLUXIE_ALLOW_RESET:-false}`. O Coolify cria a variável como `false` em toda instalação nova pelo
  compose; no staging o dono troca para `true`. Lida por `config` (ex.: `config('fluxie.allow_reset')`), nunca por `env()` fora
  do config. A troca de compose do botão Atualizar preserva variáveis (provado no projeto 4).
- **Ambiente da manutenção**: o zerar exige o mesmo que a restauração (`Maintenance::problem()`): manutenção compartilhada no
  Redis, sessão e cache fora do banco. Sem isso, recusa com a mesma mensagem.
- **Exclusão mútua**: recusa se há restauração em andamento (`RestoreProgress::isRunning()`), atualização aberta
  (`SystemUpdate::open()`) ou backup manual rodando (`ManualBackupState::isRunning()`); e a restauração, a atualização e o
  backup manual também recusam enquanto o zerar roda.
- **Só admin** (`can:admin`), na tela e na ação (a ação chamada direto também confere a variável e o bloqueio).
- **Postgres 18, Laravel 13, Livewire 4.4, Flux 2**: componentes Flux free antes de componente próprio.

## Abordagens

1. **Job em fila com o sistema em manutenção (escolhida).** Usa o caminho da restauração: `Maintenance::enter()`, os
   passos, `queue:restart`, `Maintenance::leave()`, a página pública de andamento (`/restauracao`). Os passos aguentam repetição
   (idempotentes), então apertar de novo depois de uma falha continua de onde parou. Custo: mais código que o caminho 2, mas
   reaproveita o que já foi testado em produção.
2. **Tudo no clique, sem manutenção.** Menos código, mas apagar milhares de mídias estoura o tempo do request, e o worker
   continua gravando mensagens no meio. Descartada pela antimeta "gravar conversa nova no meio".
3. **Pelo Coolify, apagando volumes pela API.** Limpeza mais radical, mas depende do token do Coolify (nem toda instalação
   tem) e mexe na infraestrutura em vez do app. Descartada.

O dono escolheu a 1 em 23/11/2026.

## Decisões do dono

- Some tudo, **inclusive todos os backups** (opção A). Não há volta.
- A confirmação **impõe** que a pessoa concorde em perder todos os dados e todos os backups do sistema e **recomenda** gerar um
  backup e baixá-lo antes.
- Botão controlado por `FLUXIE_ALLOW_RESET` no Coolify: `true` no staging, `false` por padrão no compose.
- Abordagem 1 (job + manutenção).

## Como fica

### Tela (Configurações > Sistema, seção "Zerar sistema", no fim da página)

Com `FLUXIE_ALLOW_RESET=false`, a seção mostra só o texto "Desligado nesta instalação. Para ligar, defina
`FLUXIE_ALLOW_RESET=true` no Coolify e reinicie." e nenhum botão. *(Decisão minha: quem procura descobre como ligar.)*

Com `true`, um botão "Zerar sistema" (variante de perigo) abre a confirmação:

```
┌ Zerar sistema ──────────────────────────────────────┐
│ ⚠ Isto apaga TUDO e não tem volta, inclusive TODOS   │
│   os backups guardados no sistema.                   │
│   Recomendado: gere um backup e baixe-o antes.       │
│   [Ir para Backups]                                  │
│                                                      │
│ ☐ Entendo que vou perder todos os dados e todos os   │
│   backups do sistema.                                │
│ Digite o nome da empresa: [______________]           │
│ Sua senha: [______________]                          │
│                         [Cancelar] [Zerar sistema]   │
└──────────────────────────────────────────────────────┘
```

- O "Zerar sistema" final só fica ativo com a caixa marcada e o nome da empresa igual ao cadastrado (sem diferenciar maiúscula e
  espaço nas pontas). O servidor confere de novo a caixa, o nome e a **senha do admin logado** (`Hash::check`), com limite de
  tentativas por usuário.
- Se a empresa não tiver nome cadastrado, a palavra a digitar é `ZERAR`.
- O link "Ir para Backups" leva a **Configurações > Backups**, onde já existem "Fazer backup agora" e o download. *(Decisão
  minha: reaproveitar a tela pronta em vez de duplicar os botões dentro do modal.)*
- Qualquer bloqueio (variável, ambiente, restauração, atualização, backup rodando, WAHA fora do ar) aparece no modal em
  linguagem simples, e o botão final fica desativado.

### Passos (job `ResetSystemJob`, na fila, com o andamento em `RestoreProgress`)

Título na página de andamento: "Zerando o sistema". Os rótulos entram em `RestoreProgress::STEPS`.

| Chave | Rótulo | O que faz |
|-------|--------|-----------|
| `reset-down` | Entrando em manutenção | `Maintenance::enter()`; webhook, fila e agenda param |
| `reset-waha` | Desconectando o WhatsApp | para cada canal do banco: logout (ignora 404 ou sessão já desconectada) e `deleteSession()` |
| `reset-queues` | Esvaziando as filas | `queue:clear` em todas as filas do app (`inbound`, `outbound`, `history`, `media`, `default` e as que o `docker/worker.sh` escuta, menos a do próprio job), depois espera 30 s para os jobs em voo terminarem |
| `reset-media` | Apagando as mídias | apaga todos os objetos do disco de mídia |
| `reset-backups` | Apagando os backups | apaga todos os objetos do disco de backup, `restauracao/` incluído |
| `reset-databases` | Apagando bancos guardados | `AdminDatabase::drop()` em cada `stored()` e `staging()` |
| `reset-schema` | Recriando o banco | `migrate:fresh --force`, sem seed |
| `reset-cache` | Limpando o cache | apaga por varredura todas as chaves do prefixo do app no Redis, menos as que o zerar precisa (abaixo) |
| `reset-up` | Voltando ao ar | `queue:restart` e `Maintenance::leave()` |

- **WAHA só nas sessões dos canais do banco**, nunca "todas as sessões do WAHA" (antimeta do WAHA compartilhado). *(Decisão
  minha.)* Como os canais saem do banco no `reset-schema`, o `reset-waha` vem antes e a lista de sessões é lida no começo do job.
- **Ordem**: WAHA primeiro, para cortar a entrada de mensagens; banco por último entre os dados, porque é ele que guarda a lista
  de canais.
- **Repetição**: se um passo falha, o job marca o passo como falho, **fica em manutenção** e mostra o erro na página. O admin
  não consegue mais entrar (o banco pode já estar zerado), então a página de andamento mostra o comando para continuar:
  `php artisan app:reset-system --continue`, rodado no terminal do app no Coolify. O comando roda os mesmos passos em
  sequência. Cada passo é idempotente: logout e delete aceitam 404, apagar disco vazio não falha e `migrate:fresh` repete.
  O comando também exige `FLUXIE_ALLOW_RESET=true` (sem exceção) e só roda se o último andamento for um zerar que falhou ou
  que ficou `running` além do prazo. Ele pula a recusa "O sistema já está em manutenção", que o próprio zerar causou. Não existe
  `--force`. *(Decisão minha.)* A lista de sessões do WAHA lida no
  primeiro começo fica guardada no Redis junto com o andamento. *(Decisão minha: um botão sem login na página pública abriria
  uma ação destrutiva a quem tivesse o link; o terminal do Coolify já exige acesso ao painel.)*
- **Filas**: o `down` só impede os workers de pegar jobs novos; os já enfileirados carregam texto de conversa e rodariam no banco
  zerado ao voltar. Por isso o passo `reset-queues` esvazia as filas depois do WAHA (quando não entra webhook novo) e espera 30 s
  pelos jobs em voo, que no `inbound` levam segundos. Limite aceito: um job longo em voo (importação de histórico) pode gravar
  depois disso; ele falha ao gravar no banco novo (canal inexistente) e a mídia que sobrar é rara. *(Decisão minha.)*
- **Cache**: um `Cache::flush()` apagaria o estado da manutenção e o próprio andamento, que vivem no mesmo Redis. Uma lista fixa
  de chaves não cobre as chaves por ID (`contact-name:{id}`, `presence-seen:{ticket}` etc.), que colidiriam com os IDs
  reiniciados pelo `migrate:fresh`. Por isso, o passo varre (SCAN, nunca KEYS) as chaves com o prefixo do cache do app e apaga
  todas, menos o andamento (`restore:state`), o estado da manutenção e a trava do zerar. Os limitadores podem ir junto.
  *(Decisão minha.)*
- **Tempo**: o job roda numa fila que o worker escuta com tempo suficiente (sugestão: `history`, como a restauração), com
  `timeout` de 1 hora e `failOnTimeout`. O método `failed()` do job marca o andamento como falho, então um job morto por
  tempo mostra o comando de continuar em vez de sumir. As mídias e os backups são apagados em lotes para não estourar
  memória. *(Decisão minha.)*
- **Sessões de login**: ficam no Redis e apontam para usuários que deixam de existir; o próximo request as trata como
  deslogadas. Não é preciso apagá-las.
- **Fim**: o andamento termina com "Sistema zerado. Abra /instalar e use o código da aba Environment do Coolify." e um link
  para `/instalar`.

### Registro

Como a auditoria do banco some, o pedido e o resultado vão para o **log do servidor** (`Log::warning`): quem pediu (nome e
e-mail), quando, quantos canais, mídias e backups foram apagados. O log do Coolify é o único rastro depois do zerar.
*(Decisão minha.)*

## O que fica com quem constrói

- Nome exato da chave em `config/` e onde ela mora (`config/fluxie.php` já existe para `resource_uuid`).
- **Discos**: o zerar apaga a raiz do disco de mídia e do de backup, seja qual for o driver (`s3` no compose, `local` no
  desenvolvimento). Junto vão os arquivos do bloco "Enviar mídia" dos fluxos, que também são dados da instalação. *(Decisão
  minha.)*
- Como apagar todos os objetos dos discos (`deleteDirectory('')`, `allFiles` em lotes ou outra forma do Flysystem/S3); precisa
  aguentar milhares de objetos sem estourar memória.
- Os textos da página `/restauracao` (título e o redirecionamento do JS para Backups no fim), trocados para o zerar.
- Os textos finais, mantendo o sentido acima.

## Prova de aceite

1. Suíte com Redis e portão 7 verdes.
2. No staging (pré-release com o botão, Atualizar pelo app): ligar `FLUXIE_ALLOW_RESET=true` no Coolify, zerar, conferir que
   `/instalar` aparece, que o bucket `backups` e o `media` estão vazios e que o número pessoal saiu dos aparelhos conectados.
   Depois, admin novo e QR do número de teste.
