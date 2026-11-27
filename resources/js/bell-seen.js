// Sino: com o painel aberto por 3 s os alertas viram lidos; ao fechar, o destaque dos que eram novos some. Puro, sem DOM (tests/js).

export const SEEN_AFTER_MS = 3000;

// `state` guarda o timer e se o "visto" já disparou; `deps` traz as chamadas ao servidor e o relógio.
const schedule = (state, { seen, setTimer }) => {
    state.timer = setTimer(() => {
        state.fired = true;
        seen();
    }, SEEN_AFTER_MS);
};

// Alerta que chega com o sino aberto depois do "visto": precisa de mais 3 s à vista. Antes do "visto", o timer pendente já cobre.
export function bellNewWhileOpen(state, deps) {
    if (!state.fired) {
        return;
    }

    deps.clearTimer(state.timer);
    schedule(state, deps);
}

export function bellOpenChanged(open, state, deps) {
    const { release, clearTimer } = deps;

    if (open) {
        state.fired = false;
        schedule(state, deps);

        return;
    }

    clearTimer(state.timer);

    if (state.fired) {
        state.fired = false;
        release();
    }
}
