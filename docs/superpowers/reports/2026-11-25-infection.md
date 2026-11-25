# Medição do Infection (25/11/2026)

Uma rodada só, para conhecer o escore de mutação antes de decidir se e como ele entra no CI. Nada foi instalado no projeto: o
Infection rodou num container descartável.

## Resultado

| Métrica | Valor |
|---|---|
| Mutantes gerados | 7.232 |
| Mortos pelos testes | 5.785 |
| Sobreviventes (escaped) | 1.337 |
| Timeout | 50 |
| Pulados (tempo acima do configurado) | 58 |
| Erro de sintaxe | 2 |
| Sem cobertura | 0 |
| **MSI** | **81,36%** |
| **Covered MSI** | **81,36%** |
| Cobertura de código dos mutantes | 100% |
| Tempo | 1 h 05 min com 12 threads (mais ~4 min da rodada inicial com cobertura) |

Escopo: `app/Services`, `app/Actions` e `app/Policies` (170 arquivos), sobre o código do branch `feat/seguranca-ci` em `de1115b`.

### Por área

| Área | Mutantes | Sobreviventes | MSI |
|---|---:|---:|---:|
| Policies | 181 | 2 | 99% |
| Services/Alerts | 58 | 2 | 97% |
| Services/Audit | 27 | 3 | 89% |
| Services/Setup | 88 | 11 | 88% |
| Services/Backup | 891 | 118 | 87% |
| Services/Waha | 529 | 83 | 84% |
| Services/Google | 534 | 91 | 83% |
| Services/Update | 515 | 98 | 81% |
| Services/Inbox | 2.029 | 401 | 80% |
| Actions | 312 | 62 | 80% |
| Services/Bot | 1.425 | 294 | 79% |
| Services/Reset | 249 | 60 | 76% |
| Services/Reports | 80 | 22 | 72% |
| Services/Security | 90 | 29 | 68% |
| Services/Media | 164 | 61 | 63% |

Arquivos com mais sobreviventes: `Bot/Effects/LiveEffects` 54, `Reset/ResetSystem` 53, `Inbox/SpecialContent` 46,
`Backup/Restore/RestoreDatabase` 37, `Bot/DefaultWorkflow` 36, `Actions/Inbox/StartConversation` 35, `Update/Preflight` 35.

Mutadores que mais sobrevivem: `DecrementInteger` 131, `ConcatOperandRemoval` 127, `IncrementInteger` 118, `Concat` 84,
`MethodCallRemoval` 73, `CastString` 71, `LogicalAnd` 71. Boa parte é inofensiva (texto de mensagem, TTL de cache ±1 s,
`json_decode` com profundidade 15/17). Os que importam são os de lógica e de chamada removida, abaixo.

## Os 10 sobreviventes mais relevantes

Escolhidos pelo risco: segurança, restauração do banco e o código novo desta leva.

| # | Onde | Mutador | O que sobreviveu |
|---|---|---|---|
| 1 | `app/Services/Backup/Restore/RestoreDatabase.php:277-278` | MethodCallRemoval | tirar `AdminDatabase::assertName($run->incoming)` e `assertName($run->archive)` não reprova nenhum teste: a validação de nome antes do SQL de troca não tem teste próprio nesse caminho |
| 2 | `app/Services/Backup/Restore/RestoreDatabase.php:115` e `:129` | MethodCallRemoval | `assertReady()` e `assertNames($run)` podem sumir sem teste falhar |
| 3 | `app/Services/Backup/Restore/RestoreSource.php:95` e `:113` | LogicalAnd | `str_starts_with($source, 'upload:') && preg_match(UPLOAD_ID, …)` virar `||` passa: a validação do id do arquivo enviado não é exercitada com id inválido |
| 4 | `app/Policies/TicketNotePolicy.php:14` | LogicalAnd | `active && origin === EQUIPE && user_id === autor` com um `&&` trocado por `||` passa: falta o caso de usuário inativo ou de nota automática na regra de apagar nota |
| 5 | `app/Services/Backup/Restore/DumpInspector.php:39` | LogicalOr | `$at === null \|\| dbname === null` virar `&&` passa: falta dump com só um dos dois cabeçalhos |
| 6 | `app/Services/Backup/Restore/RestoreDatabase.php:289` | GreaterThan | limite do prazo da troca (`>` → `>=`) sem teste na borda |
| 7 | `app/Services/Reset/ResetSystem.php:99` | LogicalAnd, Identical | a condição de "zerar em andamento" (`isDownForMaintenance && (STARTED \|\| reset falhado)`) aceita várias trocas sem teste falhar |
| 8 | `app/Services/Security/CspReports.php:80` | Identical, LogicalAnd | o filtro `type === 'csp-violation'` não é discriminado: o item `deprecation` do teste é descartado por outro motivo (sem campos), não pelo tipo |
| 9 | `app/Services/Security/CspReports.php:101` | LogicalOr | diretiva vazia ou ausente não tem teste próprio |
| 10 | `app/Services/Security/CspReports.php:27` e `:64` | UnwrapTrim, UnwrapStrToLower, Plus | `Content-Type` com maiúsculas, espaço ou `; charset=` não é testado; o aviso do teto por hora não confere em qual contagem é emitido |

## Achado de isolamento (fora do escopo da medição)

O Infection roda a suíte em ordem aleatória. Nessa ordem, `Tests\Feature\Livewire\UsersPageTest::test_usuarios_no_painel_do_desenho`
(linha 244) falha: um teste anterior deixa usuários atendentes no banco. A suíte do projeto roda em ordem fixa e passa. Para a
medição, a cópia do `phpunit.xml` no container recebeu `executionOrder="default"`.

## Como foi rodado

- Container `ghcr.io/fluxie-tech/fluxie-php-base:8.5` (o mesmo PHP 8.5 da imagem) com `pcov` instalado
  (`install-php-extensions pcov`) e `memory_limit=-1` (o `AvatarImageTest` de imagem gigante passa de 256 MB com cobertura).
- Postgres próprio (`postgres:18-alpine`) na mesma rede Docker; cada thread usa o banco `fluxie_test_test_N` (o Infection
  passa `TEST_TOKEN`, e `LARAVEL_PARALLEL_TESTING=1` faz o Laravel separar os bancos).
- Projeto copiado para dentro do container, `composer install` e `composer require --dev infection/infection:^0.35` lá dentro;
  o `composer.json` do repositório não mudou.
- `infection.json5`: `source.directories` = `app/Services`, `app/Actions`, `app/Policies`; `timeout` 60.
- Comando: `vendor/bin/infection --threads=12 --no-progress --show-mutations=0`, com `DB_HOST` apontando para o Postgres do
  container.

## Para decidir depois (dono)

- Se o Infection entra no CI e com qual limite. Um ponto de partida seria `--min-covered-msi` um pouco abaixo de 81% para
  não regredir. Outra opção é rodar só sobre os arquivos do PR (`--git-diff-lines`). A rodada inteira leva ~1 h com 12 threads,
  o que não cabe em cada PR.
- Se os sobreviventes 1 a 10 viram testes numa leva própria. Os de restauração (1, 2, 3, 5, 6) são os de maior risco.
