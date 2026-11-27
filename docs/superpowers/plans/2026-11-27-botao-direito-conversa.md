# Botão direito na conversa: plano

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** o botão direito na conversa (Fila e Histórico) abre o menu da bolha ou um menu "Copiar" para o texto selecionado, como
no WhatsApp Web.

**Architecture:**
- Uma função pura decide o que o clique direito abre. Ela recebe o contexto do clique e devolve `native`, `selection` ou `bubble`.
- Um componente Alpine na raiz da conversa escuta `contextmenu` e chama essa função.
- O menu da bolha (`messageMenu`) ganha "abrir no ponto".
- Um menu "Copiar" único por conversa.

**Tech Stack:** Alpine (build CSP), Livewire 4.4 Blade, node --test.

**Spec:** `docs/superpowers/specs/2026-11-27-botao-direito-conversa-design.md`

## Global Constraints

- **Ordem da regra:**
  1. Com Shift, ou com o alvo dentro de `input`, `textarea`, `[contenteditable]`, `a[href]`, `img`, `video`, `audio` ou do link
     de arquivo da bolha: `native`.
  2. Seleção não vazia, toda dentro da raiz da conversa, com o clique dentro de um dos retângulos dela: `selection`.
  3. Bolha com menu (`$hasMenu`): `bubble`.
  4. Fora disso: `native`.
- **No Histórico** (`$readonly`) não existe `bubble`, só `selection` ou `native`.
- **Menu da seleção:** só "Copiar". Copia `getSelection().toString()` com `navigator.clipboard.writeText`. Se der erro, usa
  `document.execCommand('copy')`.
- **Os dois menus:**
  - abrem no ponto do clique com `placeMenu`, recebendo um retângulo de tamanho zero no ponto;
  - abrir um fecha qualquer outro aberto;
  - Esc, clique fora, rolagem e redimensionamento fecham;
  - abrem com o foco no primeiro item;
  - ao fechar com Esc, o foco volta para onde estava.
- **Teclado:** a tecla Menu (`ContextMenu`) ou Shift+F10, com o foco numa bolha que tem menu ou no botão da setinha, abre o menu
  da bolha posicionado pelo botão.
- **Código:** subconjunto CSP do Alpine (`tests/Unit/AlpineCspSubsetTest.php`), sem expressão com lógica no Blade e sem
  dependência nova. Textos em português.

## Review Focus

1. **O clique direito sobre um link dentro da bolha** abre o menu do navegador, não o do app. Teste na Tarefa 1.
2. **Texto selecionado numa bolha e clique direito em outra bolha, fora da seleção:** abre o menu da bolha clicada, não o
   "Copiar". Teste na Tarefa 1.
3. **Seleção que começa fora da conversa** (por exemplo no cabeçalho) e termina numa bolha: o navegador mostra o menu dele. Teste
   na Tarefa 1.
4. **Histórico:** o clique direito numa bolha sem seleção abre o menu do navegador. Teste na Tarefa 1, com `hasMenu` falso.
5. **Copiar sem permissão da área de transferência:** o app usa o modo antigo e não mostra erro no console. Teste na Tarefa 2.

---

### Task 1: a regra do clique direito

**Files:**
- Create: `resources/js/context-menu-rule.js`
- Test: `tests/js/context-menu-rule.test.mjs`

**Interfaces:**
- **Produces:** `export function contextMenuTarget({ shift, nativeTarget, selectionText, selectionInsideRoot, pointInSelection, bubbleHasMenu }): 'native' | 'selection' | 'bubble'`.
  - Todos os campos são booleanos, menos `selectionText` (string).
  - O tratador (Tarefa 2) calcula cada campo a partir do evento e do DOM.

- [ ] **Step 1:** testes que falham, um por linha da regra, mais os casos do Review Focus 1 a 4.
  - Exemplo: `contextMenuTarget({ shift:false, nativeTarget:false, selectionText:'abc', selectionInsideRoot:true, pointInSelection:false, bubbleHasMenu:true })` deve dar `'bubble'`.
- [ ] **Step 2:** `node --test tests/js/context-menu-rule.test.mjs` deve falhar (FAIL).
- [ ] **Step 3:** implementar a função.
- [ ] **Step 4:** rodar de novo e passar (PASS).
- [ ] **Step 5:** commit `feat: regra do botão direito na conversa`.

### Task 2: tratador, menu da seleção e menu da bolha no ponto

**Files:**
- Create: `resources/js/conversation-context-menu.js`
  - Componente `conversationContextMenu`: escuta `contextmenu` e `keydown` na raiz.
  - Contém o menu da seleção: `open`, `style`, `placed`, `copy()`.
  - Registrado como os outros (ver `resources/js/message-menu.js`) e importado em `resources/js/app.js`.
- Modify: `resources/js/message-menu.js`
  - `messageMenu` ganha `openAt(x, y)`, que posiciona com `placeMenu` a partir de `{top:y, bottom:y, right:x}`.
  - Escuta um evento de janela para abrir quando a bolha dele for o alvo (o tratador manda o id da mensagem). Cabe ao builder:
    evento de janela com o id ou `Alpine.$data`.
  - Fecha quando outro menu abre (evento `conversation-menu-open` com a identidade de quem abriu).
- Modify: `resources/views/livewire/inbox/transcript.blade.php`
  - A raiz da lista ganha `x-data="conversationContextMenu"` e o menu "Copiar" (o mesmo visual do menu da bolha, `role="menu"`,
    item com o ícone do "Copiar" de hoje).
  - Cada bolha com menu ganha `data-message-menu="{{ $message->id }}"` e `tabindex="-1"` ou outro jeito de receber o foco para o
    atalho de teclado.
  - O link de arquivo da bolha ganha um marcador (`data-native-menu`) para a regra.
- Test: `tests/js/conversation-context-menu.test.mjs`, para o que for puro (cálculo do retângulo zero e o fallback de cópia
  com stubs).
- Test: `tests/Feature/Livewire/TranscriptContextMenuTest.php`
  - A raiz tem o `x-data`.
  - As bolhas com menu têm `data-message-menu` e as do Histórico não.
  - O menu "Copiar" existe nos dois.
- Test: `tests/Unit/AlpineCspSubsetTest.php`, se precisar listar algo novo.

- [ ] **Step 1:** testes que falham.
- [ ] **Step 2:** ver os testes falharem (FAIL).
- [ ] **Step 3:** implementar.
- [ ] **Step 4:** rodar e passar: `node --test tests/js`, `DB_PORT=5433 php artisan test --filter='TranscriptContextMenuTest|AlpineCspSubsetTest|Transcript'` (PASS).
- [ ] **Step 5:** portão: Pint, PHPStan, PHPMD, `npm run build` e a suíte inteira.
- [ ] **Step 6:** commit `feat: botão direito abre o menu da mensagem e copia o texto selecionado`.

---

Depois vem a revisão do branch e a conferência no staging, nas três resoluções, depois da pré-release.
