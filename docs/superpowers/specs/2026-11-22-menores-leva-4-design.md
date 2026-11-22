# Leva 4 de menores — design curto

Data: 22/11/2026. Branch `fix/menores-leva-4`. Aprovado pelo dono em 22/11.

## Por quê

Fechar num PR só os menores pendentes que vivem no app, sem decisão aberta, antes da pré-release `v0.2.0-rc.2`
(uma ida ao staging confere o redesenho #146 e esta leva; a própria atualização para a rc.2 prova o item 2).

## Objetivos

1. **Erro do WAHA no cartão do canal.** `App\Livewire\Settings\ChannelCard::fail` hoje concatena `$exception->getMessage()`
   (sai cru, em inglês, ex.: "URI must include a scheme and host"). Passa a mostrar uma frase em português pelo tipo da falha
   (WAHA sem resposta/conexão recusada, sessão fora do ar, recusa do WAHA, genérica); o detalhe técnico vai para `report()`.
   `SendFailure::reason` não serve como está (fala de envio de mensagem, não de canal).
2. **Aviso de versão no sino.** Ao concluir a atualização para X (`UpdateWatchCommand::watchRestart` → `ApplyUpdate::succeed`),
   marcar como lidas as notificações `NewVersionAvailable` com `version` = X dos admins. Não apagar (o sino guarda histórico).
3. **`ProductionDependenciesTest`.** Além dos `use` no topo, ver nomes de classe totalmente qualificados escritos no código
   (`\Vendor\Pacote\Classe`).
4. **`SideDatabaseTest::test_verify_nao_deixa_conexao_aberta_no_banco_novo` instável no `--parallel`.** Primeiro reproduzir
   (repetição com carga), registrando `backend_type`/`state` da sessão que sobra — essa reprodução é o RED. Correção esperada só no
   teste: contar só `backend_type = 'client backend'`, esperar até ~2 s a contagem zerar, e conferir que `pgsql_side` saiu de
   `DB::getConnections()`. Se a sessão de cliente não some nem esperando, a correção vai para `AdminDatabase::forget` e o dono é
   avisado antes.
5. **Páginas de erro próprias** 403, 404, 419, 500 e 503 em `resources/views/errors/`, no arranjo do cartão do login do redesenho
   (logo, código, título, frase em português, botão "Voltar ao início"), sem barra lateral, logado ou não (decisão do dono).
   O 503 mantém as duas mensagens atuais (restauração em andamento / manutenção) e a recarga automática.
6. **Som do alerta de WhatsApp caído (`connection_down`)** (decisão do dono: "as duas coisas"): fica calado quando os 4 sons de
   Meus alertas (`sound.message/queue/assigned/sla`) estão desligados, e só toca depois de um gesto na página
   (`navigator.userActivation.hasBeenActive`). A faixa vermelha de WhatsApp caído aparece sempre. Regra pura em
   `resources/js/alerts-rules.js`, testada em `node --test tests/js`.
7. **Apagar `App\Services\Update\Version::isRelease()`** (devolve sempre `true`, sem quem chame).

## Não-objetivos

- Página do proxy do Coolify (Traefik) durante o reinício: configuração do Coolify, não do app.
- Chave nova de som em Meus alertas; mudança nos outros alertas.
- Qualquer mudança no compose, no papel do banco ou na instalação.

## Restrições

- TDD em cada item (RED visto antes do código).
- Minha decisão: a página 500 não depende de banco, sessão, usuário nem Livewire (não pode quebrar junto com o erro).
- Visual: só componentes e tokens que o redesenho já usa (Flux free antes de componente próprio).

## Approaches

- **Escolhida: um PR de leva** (molde #129–#131), cada item no lugar dono da regra. Barato de revisar; uma pré-release só.
- PRs separados por item: descartado, sete revisões e sete idas ao staging para mudanças de poucas linhas.
- Item 6 com chave própria "WhatsApp caído" em Meus alertas, ou só exigir o gesto: o dono escolheu as duas condições acima.
- Item 5 dentro do layout do app com barra lateral: descartado pelo dono (depende de usuário e banco, pior no 500).

## Prova

Portão 6 (revisor-final), portão 7 completo (inclui suíte com Redis) e conferência visual das páginas de erro em 720p, 1080p,
2K e celular (conferente-visual).

## Para o builder

Textos exatos das frases (itens 1 e 5), a forma de esperar no item 4 e o ponto exato de marcar como lido no item 2.
