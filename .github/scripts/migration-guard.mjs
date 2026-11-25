// Reprova migration com instrução destrutiva no up() sem o marcador `// contract: <motivo>` (expand/contract: apagar,
// renomear ou mudar tipo de coluna quebra a versão anterior durante o rollback). Uso: node .github/scripts/migration-guard.mjs <arquivos>
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const DESTRUCTIVE = new RegExp([
    String.raw`dropColumns?\b`, String.raw`renameColumn\b`, String.raw`dropConstrainedForeignId(?:For)?\b`,
    String.raw`dropTimestamps(?:Tz)?\b`, String.raw`dropSoftDeletes(?:Tz)?\b`, String.raw`dropMorphs\b`,
    String.raw`dropNullableMorphs\b`, String.raw`dropRememberToken\b`, String.raw`->change\s*\(\)`, String.raw`->drop\(`,
    String.raw`->dropIfExists\(`, String.raw`->rename\(`, String.raw`Schema::dropIfExists\(`, String.raw`Schema::drop\(`,
    String.raw`Schema::rename\(`,
].join('|'), 'g');
const MARKER = /\/\/ contract: +\S/;

// Troca comentários e strings por espaços (mantendo as quebras de linha), para a busca e a contagem de chaves.
// shortcut: não entende heredoc/nowdoc do PHP (as migrations do projeto não usam) nem SQL cru em string
// (`DB::statement('ALTER TABLE … DROP …')` não é analisado); tratar na revisão se aparecer.
function blank(source) {
    let out = '';
    for (let i = 0; i < source.length; i++) {
        const c = source[i];
        const next = source[i + 1];
        let end = -1;
        if (c === '/' && next === '/' || c === '#') {
            end = source.indexOf('\n', i);
            end = end === -1 ? source.length : end;
        } else if (c === '/' && next === '*') {
            end = source.indexOf('*/', i + 2);
            end = end === -1 ? source.length : end + 2;
        } else if (c === "'" || c === '"') {
            end = i + 1;
            while (end < source.length && source[end] !== c) end += source[end] === '\\' ? 2 : 1;
            end += 1;
        }
        if (end === -1) {
            out += c;
        } else {
            out += source.slice(i, end).replace(/[^\n]/g, ' ');
            i = end - 1;
        }
    }
    return out;
}

export function unmarkedDestructive(source) {
    const code = blank(source);
    const start = code.search(/function\s+up\s*\(/);
    if (start === -1) return [];
    let i = code.indexOf('{', start);
    if (i === -1) return [];
    const from = i;
    for (let depth = 0; i < code.length; i++) {
        if (code[i] === '{') depth++;
        if (code[i] === '}' && --depth === 0) break;
    }
    const lines = source.split('\n');
    const found = [];
    for (const m of code.slice(from, i).matchAll(DESTRUCTIVE)) {
        const line = code.slice(0, from + m.index).split('\n').length;
        if (!MARKER.test(lines[line - 1]) && !(line > 1 && MARKER.test(lines[line - 2]))) {
            found.push({ line, op: m[0] });
        }
    }
    return found;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    let total = 0;
    for (const file of process.argv.slice(2)) {
        for (const f of unmarkedDestructive(readFileSync(file, 'utf8'))) {
            console.error(`${file}:${f.line} ${f.op}`);
            total++;
        }
    }
    console.log(total === 0
        ? 'migrations: nenhuma instrução destrutiva sem marcador contract'
        : `migrations: ${total} instrução(ões) destrutiva(s) sem marcador "// contract: <motivo>"`);
    process.exit(total === 0 ? 0 : 1);
}
