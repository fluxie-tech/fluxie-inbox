// Balão da conversa: edição inline e menu de ações da mensagem (resources/views/livewire/inbox/transcript.blade.php).
// A lógica fica aqui, e não em `x-data` inline, porque o build CSP do Alpine não aceita método dentro do atributo.

import { pointRect } from './context-menu-helpers.js';
import { placeMenu } from './menu-placement.js';
import { shown } from './shown.js';

document.addEventListener('alpine:init', () => {
    // Estado de edição do balão (o menu, que é filho, chama `startEdit` pelo escopo).
    window.Alpine.data('messageEdit', () => ({
        editing: false,
        text: '',

        startEdit(body) {
            this.text = body;
            this.editing = true;
        },

        saveEdit(id) {
            this.$wire.$parent.editMessage(id, this.text);
            this.editing = false;
        },
    }));

    // O menu é `fixed`: dentro da área rolável da conversa ele seria cortado pelo `overflow` e passaria por baixo do campo de resposta.
    // A posição vem do botão (abaixo dele, ou acima se não couber) ou, no botão direito, do ponto do clique; sempre dentro da tela.
    window.Alpine.data('messageMenu', () => ({
        open: false,
        info: false,
        style: '',
        // A entrada só toca depois de posicionar: antes disso o menu está escondido e a animação seria invisível.
        placed: false,
        // Quem tinha o foco antes de abrir: o Esc devolve o foco a ele.
        returnTo: null,

        toggle() {
            if (this.open) {
                this.close();
            } else {
                this.show(null);
            }
        },

        // Botão direito na bolha (resources/js/conversation-context-menu.js): abre no ponto do clique.
        openAt(x, y) {
            this.show({ x, y });
        },

        close() {
            this.open = false;
            this.info = false;
            this.placed = false;
        },

        // Abrir um menu da conversa fecha qualquer outro (`conversation-menu-open` leva o dono do menu que abriu, ou nada).
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

        show(point) {
            window.dispatchEvent(new CustomEvent('conversation-menu-open', { detail: this.$root }));
            this.returnTo = document.activeElement;
            this.info = false;
            this.placed = false;
            this.open = true;
            this.style = 'top:0;left:0;visibility:hidden';
            this.$nextTick(async () => {
                await shown(this.$refs.menu);
                this.place(point);
                this.$nextTick(() => this.$refs.menu.querySelector('[role="menuitem"]')?.focus());
            });
        },

        place(point) {
            const menu = this.$refs.menu;
            const { top, left, above } = placeMenu({
                button: point ? pointRect(point.x, point.y, menu.offsetWidth) : this.$refs.button.getBoundingClientRect(),
                menuWidth: menu.offsetWidth,
                menuHeight: menu.offsetHeight,
                area: this.$el.closest('.overflow-y-auto')?.getBoundingClientRect() ?? null,
                viewportWidth: window.innerWidth,
                viewportHeight: window.innerHeight,
            });

            // A animação cresce do lado do botão (o menu fica alinhado à direita dele) ou do ponto do clique (à direita do ponto).
            this.style = `top:${top}px;left:${left}px;transform-origin:${above ? 'bottom' : 'top'} ${point ? 'left' : 'right'}`;
            this.placed = true;
        },

        copy(body) {
            navigator.clipboard.writeText(body);
        },

        // "Adicionar texto às notas": leva o texto ao compositor, no modo nota (resources/js/composer-mode.js).
        noteFrom(body) {
            this.$dispatch('note-from-message', body);
            this.open = false;
        },
    }));
});
