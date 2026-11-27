import assert from 'node:assert/strict';
import test from 'node:test';
import { copyText, pointInRects, pointRect } from '../../resources/js/context-menu-helpers.js';

test('pointRect: retângulo de tamanho zero no ponto, com a direita à largura do menu para abrir à direita do ponto', () => {
    assert.deepEqual(pointRect(300, 200, 150), { top: 200, bottom: 200, right: 450 });
});

test('pointInRects: o ponto cai dentro de um dos retângulos da seleção', () => {
    const rects = [{ left: 10, right: 100, top: 10, bottom: 30 }, { left: 10, right: 60, top: 30, bottom: 50 }];

    assert.equal(pointInRects(rects, 50, 20), true);
    assert.equal(pointInRects(rects, 50, 40), true);
    assert.equal(pointInRects(rects, 90, 40), false, 'segunda linha é mais curta');
    assert.equal(pointInRects(rects, 500, 500), false);
    assert.equal(pointInRects([], 50, 20), false);
});

test('copyText: grava pela API da área de transferência e não usa o modo antigo', async () => {
    const calls = [];

    await copyText('abc', { clipboard: { writeText: async (t) => calls.push(['api', t]) }, legacy: () => calls.push(['legacy']) });

    assert.deepEqual(calls, [['api', 'abc']]);
});

test('copyText: sem permissão da área de transferência usa o modo antigo, sem lançar erro', async () => {
    const calls = [];
    const clipboard = { writeText: async () => { throw new DOMException('negado', 'NotAllowedError'); } };

    await copyText('abc', { clipboard, legacy: () => calls.push('legacy') });

    assert.deepEqual(calls, ['legacy']);
});

test('copyText: sem a API (contexto inseguro) usa o modo antigo', async () => {
    const calls = [];

    await copyText('abc', { clipboard: undefined, legacy: () => calls.push('legacy') });

    assert.deepEqual(calls, ['legacy']);
});
