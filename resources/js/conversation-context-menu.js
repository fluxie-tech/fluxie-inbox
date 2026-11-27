// Botão direito na conversa, como no WhatsApp Web (resources/views/livewire/inbox/transcript.blade.php): na bolha abre o menu dela,
// no texto selecionado abre o "Copiar". Link, imagem, vídeo, arquivo e campo ficam com o menu do navegador. A decisão está em
// context-menu-rule.js; o menu da bolha é o `messageMenu` (message-menu.js). A lógica fica aqui porque o build CSP do Alpine não
// aceita método dentro do atributo.

import { contextMenuTarget } from './context-menu-rule.js';
import { copyText, pointInRects, pointRect } from './context-menu-helpers.js';
import { placeMenu } from './menu-placement.js';
import { shown } from './shown.js';

const NATIVE = 'input, textarea, [contenteditable], a[href], img, video, audio, [data-document-card]';
const EDITABLE = 'input, textarea, [contenteditable]';

/** Seleção não vazia, toda dentro da raiz da conversa. */
function selectionInside(selection, root) {
    return selection.rangeCount > 0 && !selection.isCollapsed
        && Array.from({ length: selection.rangeCount }, (_, i) => selection.getRangeAt(i)).every((range) => root.contains(range.commonAncestorContainer));
}

/** O clique cai num dos retângulos da seleção (um por linha). */
function pointOnSelection(selection, x, y) {
    return Array.from({ length: selection.rangeCount }, (_, i) => selection.getRangeAt(i)).some((range) => pointInRects(range.getClientRects(), x, y));
}

/** O `messageMenu` da bolha, ou nada se ela não tiver. */
function bubbleMenu(bubble) {
    const root = bubble?.querySelector('[x-data="messageMenu"]');

    return root ? window.Alpine.$data(root) : null;
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('conversationContextMenu', () => ({
        open: false,
        style: '',
        placed: false,
        text: '',
        returnTo: null,

        onContextMenu(event) {
            const bubble = event.target.closest('[data-message-menu]');
            const selection = window.getSelection();
            const menu = bubbleMenu(bubble);

            // Tecla Menu e Shift+F10 também disparam `contextmenu` (sem o botão direito): quem abre é o `onKeydown`; aqui só se
            // impede o menu do navegador por cima, e sem fechar o menu que ele acabou de abrir.
            if (event.button !== 2 && !event.ctrlKey) {
                if (menu) {
                    event.preventDefault();
                }

                return;
            }

            const decision = contextMenuTarget({
                shift: event.shiftKey,
                nativeTarget: event.target.closest(NATIVE) !== null,
                selectionText: selection.toString(),
                selectionInsideRoot: selectionInside(selection, this.$root),
                pointInSelection: pointOnSelection(selection, event.clientX, event.clientY),
                bubbleHasMenu: menu !== null,
            });

            // Qualquer botão direito na conversa fecha o menu que estiver aberto; o escolhido abre em seguida.
            window.dispatchEvent(new CustomEvent('conversation-menu-open', { detail: null }));

            if (decision === 'selection') {
                event.preventDefault();
                this.showAt(event.clientX, event.clientY, selection.toString());
            } else if (decision === 'bubble') {
                event.preventDefault();
                menu.openAt(event.clientX, event.clientY);
            }
        },

        // Botão direito fora da conversa também fecha os menus abertos.
        outside(event) {
            if (!this.$root.contains(event.target)) {
                window.dispatchEvent(new CustomEvent('conversation-menu-open', { detail: null }));
            }
        },

        // Tecla Menu ou Shift+F10 numa bolha com menu (ou no botão dela): abre o menu da bolha pelo botão, como no clique na setinha.
        onKeydown(event) {
            if (event.key !== 'ContextMenu' && !(event.key === 'F10' && event.shiftKey)) {
                return;
            }

            const menu = event.target.closest(EDITABLE) ? null : bubbleMenu(event.target.closest('[data-message-menu]'));

            if (menu) {
                event.preventDefault();
                menu.toggle();
            }
        },

        closeUnless(event) {
            if (event.detail !== this.$root) {
                this.open = false;
            }
        },

        escape() {
            if (this.open) {
                this.open = false;
                this.returnTo?.focus();
            }
        },

        showAt(x, y, text) {
            this.text = text;
            this.returnTo = document.activeElement;
            this.placed = false;
            this.open = true;
            this.style = 'top:0;left:0;visibility:hidden';
            this.$nextTick(async () => {
                const menu = this.$refs.menu;
                await shown(menu);
                const { top, left, above } = placeMenu({
                    button: pointRect(x, y, menu.offsetWidth),
                    menuWidth: menu.offsetWidth,
                    menuHeight: menu.offsetHeight,
                    area: this.$root.closest('.overflow-y-auto')?.getBoundingClientRect() ?? null,
                    viewportWidth: window.innerWidth,
                    viewportHeight: window.innerHeight,
                });

                this.style = `top:${top}px;left:${left}px;transform-origin:${above ? 'bottom' : 'top'} left`;
                this.placed = true;
                this.$nextTick(() => menu.querySelector('[role="menuitem"]')?.focus());
            });
        },

        // O texto é o da seleção no momento do clique; o modo antigo copia a seleção que ainda estiver ativa.
        copySelection() {
            copyText(this.text, { clipboard: navigator.clipboard, legacy: () => document.execCommand('copy') });
        },
    }));
});
