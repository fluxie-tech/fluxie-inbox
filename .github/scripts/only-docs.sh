#!/usr/bin/env bash
# Lê caminhos do stdin; sai 0 só se houver ao menos um e todos forem docs (em docs/ ou .md na raiz).
# Lista de permissão: qualquer outro caminho com / (ex.: public/vendor/x/NOTICE.md) conta como código.
set -euo pipefail
n=0
while IFS= read -r f || [ -n "$f" ]; do
  case "$f" in
    docs/*) ;;
    */*) exit 1 ;;
    *.md) ;;
    *) exit 1 ;;
  esac
  n=$((n + 1))
done
[ "$n" -gt 0 ]
