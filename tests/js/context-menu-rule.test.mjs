import assert from 'node:assert/strict';
import test from 'node:test';
import { contextMenuTarget } from '../../resources/js/context-menu-rule.js';

const BASE = { shift: false, nativeTarget: false, selectionText: '', selectionInsideRoot: false, pointInSelection: false, bubbleHasMenu: true };
const rule = (over) => contextMenuTarget({ ...BASE, ...over });

test('com Shift o menu é o do navegador, mesmo com seleção e bolha com menu', () => {
    assert.equal(rule({ shift: true, selectionText: 'abc', selectionInsideRoot: true, pointInSelection: true }), 'native');
});

test('sobre link, imagem, vídeo, arquivo ou campo (alvo nativo) o menu é o do navegador', () => {
    assert.equal(rule({ nativeTarget: true }), 'native');
    assert.equal(rule({ nativeTarget: true, selectionText: 'abc', selectionInsideRoot: true, pointInSelection: true }), 'native');
});

test('seleção não vazia dentro da conversa, com o clique nela, abre o Copiar', () => {
    assert.equal(rule({ selectionText: 'abc', selectionInsideRoot: true, pointInSelection: true }), 'selection');
});

test('no Histórico (sem menu de bolha) a seleção também abre o Copiar', () => {
    assert.equal(rule({ bubbleHasMenu: false, selectionText: 'abc', selectionInsideRoot: true, pointInSelection: true }), 'selection');
});

test('seleção só de espaços não abre o Copiar', () => {
    assert.equal(rule({ selectionText: '  \n', selectionInsideRoot: true, pointInSelection: true }), 'bubble');
});

test('texto selecionado e clique em outra bolha, fora da seleção, abre o menu da bolha clicada', () => {
    assert.equal(rule({ selectionText: 'abc', selectionInsideRoot: true, pointInSelection: false }), 'bubble');
});

test('seleção que começa fora da conversa deixa o menu do navegador quando a bolha não tem menu', () => {
    assert.equal(rule({ bubbleHasMenu: false, selectionText: 'abc', selectionInsideRoot: false, pointInSelection: true }), 'native');
});

test('seleção que começa fora da conversa, com o clique nela, deixa o menu do navegador mesmo na bolha com menu', () => {
    assert.equal(rule({ selectionText: 'abc', selectionInsideRoot: false, pointInSelection: true }), 'native');
});

test('seleção que vaza da conversa, com o clique fora dela, abre o menu da bolha clicada', () => {
    assert.equal(rule({ selectionText: 'abc', selectionInsideRoot: false, pointInSelection: false }), 'bubble');
});

test('sem seleção, bolha com menu abre o menu da bolha', () => {
    assert.equal(rule({}), 'bubble');
});

test('Histórico: sem seleção e sem menu de bolha o menu é o do navegador', () => {
    assert.equal(rule({ bubbleHasMenu: false }), 'native');
});
