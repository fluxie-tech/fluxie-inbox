# Eco da resposta de ligação relido pela recuperação — Plano

> Correção de bug depois do merge do #156 (spec `docs/superpowers/specs/2026-11-24-waha-midia-e-ligacoes-design.md`).

**Causa (staging, 24/11, v0.2.0-rc.5):** ao vivo, o eco da resposta automática do app `calls` chega com `source: "api"` e é
ignorado. A recuperação (`RecoverMessages::ingest`) relê a mesma mensagem pela listagem `chats/all/messages`, que traz
`source: "app"` (o GOWS não guarda a origem), e o `CallReplyEcho` deixa passar: a mensagem entra como "fora do painel" no
ticket aberto.

**Correção:** `CallReplyEcho::matches` exige `source === 'api'` só no evento ao vivo (sem `payload.mode`); nos modos
`recovered` e `imported`, casa por `fromMe`, sem mídia, texto e `CallSettings::isReplyText`. Mensagem digitada no celular
com o mesmo texto já foi gravada ao vivo, e a recuperação pula id existente.

### Task 1: Recuperação ignora o eco mesmo com `source: "app"`

**Files:** `app/Services/Inbox/CallReplyEcho.php`, `tests/Feature/Inbox/CallReplyEchoTest.php`

- [ ] RED: `test_eco_relido_pela_recuperacao_com_source_app_e_ignorado` e `test_eco_importado_com_source_app_e_ignorado`
  (pela entrada `RecoverMessages::ingest`) → nenhuma mensagem nem ticket.
- [ ] GREEN: a regra acima; os testes existentes seguem passando (inclusive o do celular ao vivo com `source: "app"`).
- [ ] Pint, PHPStan, PHPMD (sem supressor), testes afetados; commit `fix:`.
- [ ] PR, CI verde, merge com OK do dono, pré-release `v0.2.0-rc.6`, staging: ligar, recuperar, nenhum eco novo no ticket.
