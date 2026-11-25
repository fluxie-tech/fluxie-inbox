import assert from 'node:assert/strict';
import test from 'node:test';
import { unmarkedDestructive } from '../../.github/scripts/migration-guard.mjs';

const migration = (up, down = '') => `<?php
return new class extends Migration
{
    public function up(): void
    {
${up}
    }

    public function down(): void
    {
${down}
    }
};
`;

// A linha do `up` informada é relativa ao corpo; o cabeçalho tem 5 linhas antes dela.
test('dropColumn no up sem marcador reprova', () => {
    const src = migration("        Schema::table('t', fn ($t) => $t->dropColumn('a'));");
    assert.deepEqual(unmarkedDestructive(src), [{ line: 6, op: 'dropColumn' }]);
});

test('marcador na linha anterior libera', () => {
    const src = migration("        // contract: coluna sem uso desde a v1.2\n        $t->dropColumn('a');");
    assert.deepEqual(unmarkedDestructive(src), []);
});

test('marcador na mesma linha libera', () => {
    const src = migration("        $t->dropColumn('a'); // contract: sem uso");
    assert.deepEqual(unmarkedDestructive(src), []);
});

test('marcador sem motivo não libera', () => {
    const src = migration("        // contract:\n        $t->dropColumn('a');");
    assert.equal(unmarkedDestructive(src).length, 1);
});

test('dropColumn só no down passa', () => {
    assert.deepEqual(unmarkedDestructive(migration("        $t->string('a');", "        $t->dropColumn('a');")), []);
});

test('palavra em comentário ou string não conta', () => {
    const src = migration("        // dropColumn antigo\n        /* renameColumn */\n        $x = 'dropColumn { ->change()';\n        $y = \"Schema::drop(\";");
    assert.deepEqual(unmarkedDestructive(src), []);
});

test('->change() e Schema::rename reprovam', () => {
    const src = migration("        $t->string('a')->change();\n        Schema::rename('a', 'b');");
    assert.deepEqual(unmarkedDestructive(src).map((f) => f.op), ['->change()', 'Schema::rename(']);
});

test('dropForeign e dropIndex passam', () => {
    assert.deepEqual(unmarkedDestructive(migration("        $t->dropForeign(['a']);\n        $t->dropIndex('b');")), []);
});

test('migration sem up() devolve vazio', () => {
    assert.deepEqual(unmarkedDestructive('<?php return 1;'), []);
});

for (const [forma, op] of [
    ["$t->dropConstrainedForeignId('a');", 'dropConstrainedForeignId'],
    ["$t->dropConstrainedForeignIdFor(User::class);", 'dropConstrainedForeignIdFor'],
    ['$t->dropTimestamps();', 'dropTimestamps'],
    ['$t->dropTimestampsTz();', 'dropTimestampsTz'],
    ['$t->dropSoftDeletes();', 'dropSoftDeletes'],
    ['$t->dropSoftDeletesTz();', 'dropSoftDeletesTz'],
    ["$t->dropMorphs('a');", 'dropMorphs'],
    ["$t->dropNullableMorphs('a');", 'dropNullableMorphs'],
    ['$t->dropRememberToken();', 'dropRememberToken'],
    ["Schema::connection('x')->drop('t');", '->drop('],
    ["Schema::connection('x')->dropIfExists('t');", '->dropIfExists('],
    ["Schema::connection('x')->rename('a', 'b');", '->rename('],
    ["$t->string('a')->change ();", '->change ()'],
]) {
    test(`${op} no up sem marcador reprova`, () => {
        assert.deepEqual(unmarkedDestructive(migration(`        ${forma}`)).map((f) => f.op), [op]);
    });
}
