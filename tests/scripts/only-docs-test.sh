#!/usr/bin/env bash
# Teste de .github/scripts/only-docs.sh: sai 0 só se houver caminho e todos forem docs.
cd "$(dirname "$0")/../.." || exit 1
fail=0
check() { if [ "$2" = "$3" ]; then :; else echo "FALHA: $1 (esperado '$2', veio '$3')"; fail=1; fi; }
run() { printf "$1" | .github/scripts/only-docs.sh >/dev/null 2>&1; echo $?; }
check "docs/a.md" 0 "$(run 'docs/a.md\n')"
check "docs + README" 0 "$(run 'docs/superpowers/plans/x.md\nREADME.md\n')"
check "CLAUDE.md" 0 "$(run 'CLAUDE.md\n')"
check "docs + código" 1 "$(run 'docs/a.md\napp/Models/User.php\n')"
check "workflow" 1 "$(run '.github/workflows/tests.yml\n')"
check ".md em resources/" 1 "$(run 'resources/views/mail/x.md\n')"
check ".md em tests/" 1 "$(run 'tests/Fixtures/a.md\n')"
check "entrada vazia" 1 "$(run '')"
check "nome com espaço" 0 "$(run 'docs/com espaço.md\n')"
check "nome acentuado" 0 "$(run 'docs/ação.md\n')"
check "renomeação de código para docs (--no-renames)" 1 "$(run 'app/X.php\ndocs/X.md\n')"
exit $fail
