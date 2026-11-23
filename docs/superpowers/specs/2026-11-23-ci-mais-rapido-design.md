# CI mais rápido (auditoria de 23/11) — design

## Por quê

Auditoria dos últimos 150 runs (16 a 23/11): um PR espera 165 s pelo `tests` e 214 s quando mexe em docker (`compose-smoke`).
Parte do tempo é trabalho repetido ou feito à toa. O repositório é público e usa o GitHub Actions free: runner padrão sem custo,
runner maior fora de questão, cache de 10 GB por repositório.

## Metas

1. `security-scan`: construir a base só quando o PR muda `docker/base`. Hoje ela é construída em 8 de 16 PRs, e 7 deles não
   tocavam a pasta (~83 s de runner cada).
2. Builds da imagem runtime com cache de camadas (Composer e npm), hoje de 36 a 46 s cada.
3. Upgrade smoke em job próprio, em paralelo ao compose smoke: ~50 s a menos de espera nos PRs que mexem em docker.
4. `compose-smoke` sem o gatilho `push` na main: o `edge` do release-image já refaz o smoke e o upgrade.
5. PR só de docs (arquivo em `docs/` ou `.md` na raiz) pula os passos pesados de `tests` e `static`, com o check obrigatório verde.
6. `compose-asset` e `production` saem do `ubuntu-slim` (fila de até 11 min; o `already-tested` saiu dele pelo mesmo motivo).
7. `timeout-minutes` nos jobs `tests` (20) e `audit` (10). Hoje valem 360 min, e a suíte trava em vez de falhar sem Postgres.

## Fora de escopo

- Runner maior (pago).
- Shard da suíte.
- Cobertura só na main.
- Testes afetados no PR.
- Mudar o cache do Composer: a main não grava o lock atual, mas hoje isso custa ~0 s.

## Anti-metas

- Nenhum teste deixa de rodar num PR que mexe em código.
- O `release-image` continua dependendo só do `tests` verde.

## Abordagens escolhidas pelo dono

1. **Pulo de docs: A.** Um passo nos jobs `tests` e `static` compara o merge commit com o primeiro pai (`HEAD^1`, checkout com
   `fetch-depth: 2`). Se todo arquivo mudado estiver em `docs/` ou for `.md` na raiz, ele grava `tested=true`, a saída que os
   passos seguintes já testam.
   - Recusadas: `dorny/paths-filter` (dependência para um `git diff`) e `paths-ignore` (deixa o check obrigatório pendente).
2. **Cache Docker: A.** Usar `docker/build-push-action` com `load: true` e `cache-from: type=gha`, como o `base-image`.
   - Só o `edge` da main grava (`cache-to: type=gha,mode=max`); os PRs só leem, para caber nos 10 GB.
   - Recusada: todos gravarem, o que expulsaria o cache do Composer.
3. **Upgrade em paralelo: A.** Dois jobs, cada um com seu build (barato com o cache).
   - Recusada: passar a imagem por artifact (~500 MB de upload e download, mais um job em série).

## Decisões do construtor (minhas)

- **Comparação pelo pai do merge commit.** A detecção da base no `security-scan` passa a usar a mesma comparação com `HEAD^1`.
  A hipótese para o disparo à toa é o `base.sha` defasado em relação ao merge commit.
- **Árvore registrada no PR só de docs.** O PR só de docs ainda grava a árvore (`Record tested tree`), e o push na main a pula.
  O código é idêntico ao da árvore anterior, que já passou.
- **Scope do cache.** Todos os builds da runtime leem o mesmo cache (`scope=runtime`), e só o `edge` grava nele. Os builds do
  `edge`, do `compose-smoke` e do `security-scan` usam o mesmo target, o `runtime`.
- **Base do branch no `security-scan`.** Com a base mudada, o build continua o `docker build` com `--build-context`, porque o builder do Buildx não enxerga a imagem local (sem cache nesse caso raro).
- **Prova.** `actionlint`, se houver, ou revisão. A prova real é o CI do próprio PR, que mexe em `.github/` e dispara
  `tests`, `security` e `compose-smoke` (o caminho `.github/workflows/compose-smoke.yml`). Depois do merge, o run do `edge`
  confirma a gravação do cache.
