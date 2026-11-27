// O que o botão direito abre na conversa (resources/js/conversation-context-menu.js): o menu do navegador, o "Copiar" da seleção ou o
// menu da bolha. Pura, para testar sem navegador; quem chama calcula cada campo a partir do evento e do DOM.

/**
 * @param {{ shift: boolean, nativeTarget: boolean, selectionText: string, selectionInsideRoot: boolean, pointInSelection: boolean, bubbleHasMenu: boolean }} input
 * @returns {'native' | 'selection' | 'bubble'}
 */
export function contextMenuTarget({ shift, nativeTarget, selectionText, selectionInsideRoot, pointInSelection, bubbleHasMenu }) {
    if (shift || nativeTarget) {
        return 'native';
    }

    if (selectionText.trim() !== '' && selectionInsideRoot && pointInSelection) {
        return 'selection';
    }

    return bubbleHasMenu ? 'bubble' : 'native';
}
