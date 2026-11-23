#!/usr/bin/env bash
# Lê caminhos do stdin; sai 0 só se houver ao menos um e todos forem docs (docs/ ou .md fora do código).
set -euo pipefail
n=0
while IFS= read -r f; do
  case "$f" in
    docs/*) ;;
    app/* | config/* | database/* | resources/* | routes/* | tests/*) exit 1 ;;
    *.md) ;;
    *) exit 1 ;;
  esac
  n=$((n + 1))
done
[ "$n" -gt 0 ]
