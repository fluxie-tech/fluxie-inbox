# CI mais rápido — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** cortar trabalho repetido e feito à toa no CI do GitHub Actions, sem deixar de rodar nenhum teste num PR que mexe em código.

**Architecture:** só `.github/` e um teste de script em `tests/scripts/`.
- PR só de docs: a action `already-tested` também passa a responder no PR. A lógica do "só docs" fica num script com teste.
- Imagem runtime: o build passa ao `docker/build-push-action` com cache gha (`scope=runtime`). Só o `edge` grava; os PRs só leem.
- `compose-smoke`: vira dois jobs e perde o gatilho `push`.

**Tech Stack:** GitHub Actions (plano free, repositório público, runners padrão), bash, Docker Buildx.

**Spec:** `docs/superpowers/specs/2026-11-23-ci-mais-rapido-design.md`

## Global Constraints

- Branch `chore/auditoria-ci`, worktree `C:\Projetos\chatbot-auditoria-ci`. Nada na `main`.
- Actions fixadas por SHA com o comentário da versão, como no resto do repositório. Reusar os SHAs já presentes:
  - `actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1`
  - `docker/setup-buildx-action@f87e5991a6d7451dcb8d9637bfbc97413f497069 # v4.4.1`
  - `docker/build-push-action@c3c9e263c25d99ce0380d002d59b67737d91b0dc # v7.4.0`
- Nenhuma action nova de terceiro.
- Runner de todos os jobs: `ubuntu-26.04` (sai o `ubuntu-slim`).
- Cache gha da runtime: `type=gha,scope=runtime`.
  - Leitura: `cache-from: type=gha,scope=runtime` em todo build da runtime.
  - Gravação: `cache-to: type=gha,scope=runtime,mode=max` só no `edge` do `release-image`.
- Checks obrigatórios da `main`: `tests` e `static`. Os nomes desses jobs não mudam.
- Comentários e mensagens em português, no estilo dos arquivos atuais. Commits com prefixo (`ci:`, `test:`, `docs:`).
- Verificação de sintaxe: exatamente
  `cd /c/Projetos/chatbot-auditoria-ci && go run github.com/rhysd/actionlint/cmd/actionlint@v1.7.12 2>&1 | tail -15; echo "exit=${PIPESTATUS[0]}"`.
  - O dono liberou só esta forma do comando; não acrescentar flags nem filtros.
  - Esperado: `exit=0` e nenhuma outra linha. A linha de base no início deu `exit=0`.
  - O `.github/actionlint.yaml` (commit do plano) declara o rótulo `ubuntu-26.04`, que o 1.7.12 (a mais recente) não conhece.

## Review Focus

1. **PR que mexe em docs e código ao mesmo tempo:** a suíte roda inteira. O teste do script cobre a mistura.
2. **Nome de arquivo com espaço, ou diff vazio** (merge commit sem mudança, rebase vazio): diff vazio não é "só docs", e a
   suíte roda. O teste cobre a entrada vazia.
3. **`.md` fora de `docs/`**: só conta como docs o `.md` na raiz (README, CLAUDE.md). Lista de permissão: qualquer outro caminho com `/`
   (`resources/views/mail/x.md`, `public/vendor/emoji/NOTICE.md`, `.github/x.md`) é código, e o teste cobre esses casos.
4. **Build do `edge` no `workflow_run`:** `github.sha` não é o commit testado. O build precisa de `context: .`, o checkout do
   `head_sha`. Sem isso, a action constrói pelo contexto git do `github.sha`.
5. **`security-scan` com a base mudada:** o daemon tem a `fluxie-base:ci` local, que o builder `docker-container` do Buildx não
   enxerga. Nesse caso o build continua sendo o `docker build` de hoje, sem cache.

---

### Task 1: PR só de docs pula a suíte e o static

**Files:**
- Create: `.github/scripts/only-docs.sh`
- Create: `tests/scripts/only-docs-test.sh`
- Modify: `.github/actions/already-tested/action.yml` (description, lookup)
- Modify: `.github/workflows/tests.yml:69-80` e `:151-162` (checkout com `fetch-depth: 2`; passo `tested` sem `if: push`)
- Modify: `.github/workflows/compose-smoke.yml:39-40` (o passo de teste dos scripts roda os dois testes)

**Interfaces:**
- Produces: `.github/scripts/only-docs.sh`, executável.
  - Lê caminhos do stdin, um por linha.
  - Sai com 0 só se houver pelo menos um caminho e todos forem docs. Sai com 1 em qualquer outro caso.
  - Docs é um caminho em `docs/` ou um `.md` na raiz. Qualquer outro caminho com `/` não é docs (lista de permissão, falha fechada).
- Produces: a action `already-tested` grava `tested=true` em dois casos:
  - push, com a árvore já testada num PR (como hoje);
  - pull_request, quando `git diff --name-only HEAD^1 HEAD | .github/scripts/only-docs.sh` sai com 0.
- A action recebe o evento por `github.event_name`, sem input novo. Qualquer erro continua deixando `tested=false`.

- [ ] **Step 1: Escrever o teste** `tests/scripts/only-docs-test.sh`, no estilo do `coolify-test.sh`: `cd` para a raiz,
  `check <nome> <esperado> <veio>`, sai com 1 se algo falhar. Casos, como `printf '...' | .github/scripts/only-docs.sh; echo $?`:
  - `docs/a.md` → 0
  - `docs/superpowers/plans/x.md` + `README.md` → 0
  - `CLAUDE.md` → 0
  - `docs/a.md` + `app/Models/User.php` → 1
  - `.github/workflows/tests.yml` → 1
  - `resources/views/mail/x.md` → 1
  - `tests/Fixtures/a.md` → 1
  - entrada vazia → 1
  - `docs/com espaço.md` → 0
- [ ] **Step 2: Ver o teste falhar.** `bash tests/scripts/only-docs-test.sh` → FALHA (script inexistente).
- [ ] **Step 3: Implementar** `.github/scripts/only-docs.sh` (bash, `set -euo pipefail`, laço `while IFS= read -r`).
- [ ] **Step 4: Ver o teste passar.** `bash tests/scripts/only-docs-test.sh` → sem saída, exit 0. Rodar também
  `bash tests/scripts/coolify-test.sh` → exit 0.
- [ ] **Step 5: Ligar o script na action e nos workflows.**
  - `already-tested`: no pull_request, o lookup usa o script. Atualizar a `description` e a notice, por exemplo
    "PR só de docs; suíte não repetida".
  - `tests.yml`: tirar `if: github.event_name == 'push'` dos dois passos `tested` e pôr `fetch-depth: 2` nos dois checkouts.
    Atualizar o comentário das linhas 17-21: o passo também pula PR só de docs.
  - `compose-smoke.yml:39-40`: o passo vira "Scripts tests" e roda `bash tests/scripts/coolify-test.sh` e
    `bash tests/scripts/only-docs-test.sh`.
- [ ] **Step 6: actionlint** (ver Global Constraints) → sem erro nos arquivos tocados.
- [ ] **Step 7: Commit** `ci: PR só de docs pula a suíte e o static`.

### Task 2: timeouts e saída do ubuntu-slim

**Files:**
- Modify: `.github/workflows/tests.yml:22-23` (`timeout-minutes: 20` no `tests`) e `:222-223` (`timeout-minutes: 10` no `audit`)
- Modify: `.github/workflows/release-image.yml:113` e `:143` (`runs-on: ubuntu-26.04`)

- [ ] **Step 1:** aplicar as quatro trocas.
- [ ] **Step 2:** `grep -rn "ubuntu-slim" .github/` → nenhuma linha; actionlint → sem erro.
- [ ] **Step 3: Commit** `ci: timeouts nos testes e fim do ubuntu-slim`.

### Task 3: release-image/edge constrói com cache e grava

**Files:**
- Modify: `.github/workflows/release-image.yml:38-44`

**Interfaces:**
- Produces: o cache gha `scope=runtime`, que as Tasks 4 e 5 leem.

- [ ] **Step 1:** antes do build, o passo `Set up Buildx` (SHA da Global Constraints). O passo `Build the runtime image` passa a
  usar o `build-push-action` com:
  - `context: .`, `file: docker/Dockerfile`, `target: runtime`
  - `build-args: VERSION=sha-${{ env.SHA }}`, `tags: ${{ env.IMAGE }}:sha-${{ env.SHA }}`
  - `load: true`, `push: false`
  - `cache-from: type=gha,scope=runtime`, `cache-to: type=gha,scope=runtime,mode=max`

  Comentário de uma linha: só este job grava o cache, para caber nos 10 GB do plano free. Os passos de smoke e push não mudam.
- [ ] **Step 2:** actionlint → sem erro.
- [ ] **Step 3: Commit** `ci: edge constrói a runtime com cache de camadas`.

### Task 4: compose-smoke em dois jobs, sem push, lendo o cache

**Files:**
- Modify: `.github/workflows/compose-smoke.yml` (todo o arquivo)

- [ ] **Step 1:** remover o bloco `push:` (linhas 13-21).
  - Atualizar o comentário do topo: na `main`, o `edge` do release-image refaz os dois testes.
  - Tirar `.github/workflows/compose-smoke.yml` dos `paths` não é preciso: continua valendo para o PR.
- [ ] **Step 2:** o job `smoke` fica com checkout, testes dos scripts (Task 1), build e `Compose smoke test`.
  - Build: `Set up Buildx`, depois o `build-push-action` com `context: .`, `file: docker/Dockerfile`, `target: runtime`,
    `tags: fluxie-smoke:ci`, `load: true` e `cache-from: type=gha,scope=runtime`, sem `cache-to`.
- [ ] **Step 3:** novo job `upgrade` (`ubuntu-26.04`, `timeout-minutes: 30`) com checkout, o mesmo build e `Upgrade smoke test`
  (`bash docker/smoke/upgrade-smoke.sh fluxie-smoke:ci`).
- [ ] **Step 4:** actionlint → sem erro.
- [ ] **Step 5: Commit** `ci: compose-smoke em dois jobs paralelos e sem push na main`.

### Task 5: security-scan detecta a base pelo pai do merge e lê o cache

**Files:**
- Modify: `.github/workflows/security-scan.yml:29-60`

- [ ] **Step 1:** checkout com `fetch-depth: 2`. O passo `Detect base image changes` compara
  `git diff --quiet HEAD^1 HEAD -- docker/base`, sem o `git fetch` do `BASE_SHA` e sem o `env`.
  - Atualizar o comentário das linhas 34-37: o merge commit contra o primeiro pai dá só a mudança do PR; o `base.sha` podia
    estar defasado.
- [ ] **Step 2:** dividir o build da runtime em dois passos.
  - **Base mudada:** quando `steps.base.outputs.changed == 'true'`, o `docker build` de hoje, com o `--build-context`.
  - **Senão:** `Set up Buildx` e o `build-push-action` com `context: .`, `file: docker/Dockerfile`, `target: runtime`,
    `tags: fluxie-scan:ci`, `load: true` e `cache-from: type=gha,scope=runtime`.
  - Comentário de uma linha: o Buildx não enxerga a base local, por isso o caminho da base mudada não usa cache (Review Focus 5).
- [ ] **Step 3:** actionlint → sem erro.
- [ ] **Step 4: Commit** `ci: security-scan detecta a base pelo pai do merge e usa o cache`.

## Verificação (portão 7)

As mudanças são só de CI. Do portão 7 do CLAUDE.md, valem:
- os dois testes de script;
- o actionlint, se liberado;
- o CI do PR.

O PR mexe em `.github/`, então dispara:
- `tests` e `static`: devem rodar inteiros, porque o PR não é só de docs;
- `security`;
- `security-scan`: só se o PR tocar o arquivo dele, o que acontece;
- `compose-smoke`: os jobs `smoke` e `upgrade`, em paralelo.

Na log do `security-scan`, confirmar que **não** aparece `Build the base image from this branch`. No primeiro `edge` depois do
merge, confirmar a gravação do cache (`exporting cache to GitHub Actions Cache`). A suíte PHP não muda e não precisa rodar
localmente.
