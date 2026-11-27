# Botão direito na conversa (como no WhatsApp Web)

Data: 27/11/2026. Branch: `feat/botao-direito-conversa`. Mudança pequena.

## Intenção

Hoje o menu de uma mensagem (reagir, responder, copiar, adicionar às notas, dados, editar, apagar) só abre pela setinha no canto
da bolha, e copiar um trecho exige o menu do navegador. O dono quer que o botão direito funcione com o app, como no WhatsApp Web:

- **Na bolha:** o botão direito abre o mesmo menu da setinha.
- **Com texto selecionado:** o botão direito abre um menu do app com "Copiar", que copia o trecho selecionado.

## Decisões do dono

- O menu da seleção tem **só "Copiar"**.
- **Onde vale:** na conversa da Fila e na conversa do Histórico.
  - No **Histórico** só o "Copiar" da seleção. A bolha de lá não tem menu hoje (é só leitura), e continua sem.
- **Em cima de link, imagem, vídeo ou arquivo** dentro da bolha, abre o menu do navegador (abrir em nova aba, copiar link, salvar).
- **Abordagem A:** um tratador único do botão direito na área da conversa decide o que abrir.
- **Descartadas:**
  - B, cada bolha com o próprio tratador: espalha a regra por centenas de bolhas.
  - C, biblioteca de menu de contexto: dependência nova, esbarra no subconjunto CSP do Alpine e foge do visual do menu atual.

## Regra do tratador (em ordem)

O tratador escuta `contextmenu` na raiz da conversa (`resources/views/livewire/inbox/transcript.blade.php`, Fila e Histórico).

1. Shift apertado, ou o alvo está dentro de `input`, `textarea`, `[contenteditable]`, `a[href]`, `img`, `video`, `audio` ou
   do link de arquivo da bolha: não faz nada, e o navegador mostra o menu dele.
2. Há seleção de texto não vazia, toda dentro da raiz da conversa, e o clique cai dentro de um dos retângulos da seleção:
   `preventDefault` e abre o menu "Copiar" no ponto do clique.
3. O clique está numa bolha que tem o menu da setinha (`$hasMenu`): `preventDefault` e abre esse mesmo menu, posicionado no
   ponto do clique.
4. Qualquer outro caso: menu do navegador.

A decisão fica numa função pura em `resources/js/` (entra o contexto do clique, sai `native`, `selection` ou `bubble`), testada
em `tests/js`, como `menu-placement.js`.

## Menus

- **Menu da bolha:** é o de hoje (`messageMenu` em `resources/js/message-menu.js`). Ganha "abrir no ponto (x, y)".
  - `placeMenu` recebe um retângulo de tamanho zero no ponto do clique. Abre abaixo e à direita do ponto, ou acima se não
    couber, e sempre dentro da área da conversa e da tela.
  - Abrir pela setinha continua igual. Abrir um menu fecha qualquer outro menu de bolha ou de seleção que estiver aberto.
- **Menu da seleção:** um só por conversa, `fixed`, com o mesmo visual do menu da bolha (mesmas classes, `role="menu"`) e um
  item "Copiar" com o ícone do "Copiar" de hoje.
  - Copia `window.getSelection().toString()` com `navigator.clipboard.writeText`. Se a API falhar (contexto sem permissão),
    usa `document.execCommand('copy')` com a seleção ainda ativa.
  - Fecha ao clicar fora, Esc, rolar ou redimensionar, como o da bolha.
  - O texto copiado é o que o navegador dá para a seleção. Se a seleção pegar mais de uma bolha, vem o texto de todas, na ordem
    da tela.

## Teclado e acessibilidade

- Com o foco numa bolha que tem menu, ou no botão da setinha, a tecla **Menu** (ContextMenu) ou **Shift+F10** abre o menu da
  bolha, posicionado pelo botão, como no clique da setinha.
- Os dois menus abrem com o foco no primeiro item. Esc fecha e devolve o foco ao elemento que tinha antes.
- `aria-haspopup`/`aria-expanded` seguem como hoje no botão da setinha.

## Fora do escopo

- Toque longo no celular: o app é usado no computador, e o celular já tem a setinha visível sem passar o mouse.
- Botão direito na lista da Fila, nos cards ou em outras telas.
- Menu da bolha no Histórico.

## Restrições

- Subconjunto CSP do Alpine (`tests/Unit/AlpineCspSubsetTest.php`): a lógica fica em componentes registrados nos arquivos de JS,
  não em expressões no Blade.
- Nenhuma dependência nova.
- Conferência visual em 720p, 1080p e 2K, no staging, depois da pré-release.

## A critério do builder

- O nome dos arquivos e das funções de JS.
- Como o tratador chega ao componente Alpine da bolha: um evento com o id da mensagem, ou `Alpine.$data` do elemento.
- O seletor exato do link de arquivo da bolha.
