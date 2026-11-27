// Sino: com o painel aberto por 3 s os alertas viram lidos; ao fechar, o destaque dos que eram novos some. Puro, sem DOM (tests/js).

export const SEEN_AFTER_MS = 3000;

// `state` guarda o timer e se o "visto" já disparou; `deps` traz as chamadas ao servidor e o relógio.
export function bellOpenChanged(open, state, { seen, release, setTimer, clearTimer }) {
    if (open) {
        state.fired = false;
        state.timer = setTimer(() => {
            state.fired = true;
            seen();
        }, SEEN_AFTER_MS);

        return;
    }

    clearTimer(state.timer);

    if (state.fired) {
        state.fired = false;
        release();
    }
}
