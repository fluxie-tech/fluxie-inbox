import assert from 'node:assert/strict';
import test from 'node:test';
import { SEEN_AFTER_MS, bellOpenChanged } from '../../resources/js/bell-seen.js';

// Relógio falso: guarda a função agendada para o teste disparar à mão.
const harness = () => {
    const h = { state: { timer: null, fired: false }, seen: 0, release: 0, cancelled: 0, ms: null, run: null };
    h.deps = {
        seen: () => h.seen++,
        release: () => h.release++,
        setTimer: (fn, ms) => ((h.run = fn), (h.ms = ms), 1),
        clearTimer: () => h.cancelled++,
    };

    return h;
};

test('abrir agenda o "visto" para 3 s e só então chama', () => {
    const h = harness();

    bellOpenChanged(true, h.state, h.deps);
    assert.equal(h.ms, SEEN_AFTER_MS);
    assert.equal(SEEN_AFTER_MS, 3000);
    assert.equal(h.seen, 0);

    h.run();
    assert.equal(h.seen, 1);
});

test('fechar antes dos 3 s cancela e não libera nada', () => {
    const h = harness();

    bellOpenChanged(true, h.state, h.deps);
    bellOpenChanged(false, h.state, h.deps);

    assert.equal(h.cancelled, 1);
    assert.equal(h.release, 0);
});

test('fechar depois de visto libera o destaque uma vez', () => {
    const h = harness();

    bellOpenChanged(true, h.state, h.deps);
    h.run();
    bellOpenChanged(false, h.state, h.deps);
    bellOpenChanged(false, h.state, h.deps);

    assert.equal(h.release, 1);
});
