// Contas do botão direito na conversa (resources/js/conversation-context-menu.js e message-menu.js), sem tocar no DOM para testar em node.

/** Retângulo de tamanho zero no ponto do clique, para `placeMenu`: com a direita à largura do menu, ele abre à direita do ponto. */
export function pointRect(x, y, menuWidth) {
    return { top: y, bottom: y, right: x + menuWidth };
}

/** O ponto cai dentro de algum dos retângulos (os `getClientRects()` de uma seleção, um por linha). */
export function pointInRects(rects, x, y) {
    return Array.from(rects).some((r) => x >= r.left && x <= r.right && y >= r.top && y <= r.bottom);
}

/** Copia pela API da área de transferência; sem ela ou sem permissão, cai no modo antigo (`legacy`) sem lançar erro. */
export async function copyText(text, { clipboard, legacy }) {
    try {
        await clipboard.writeText(text);
    } catch {
        legacy();
    }
}
