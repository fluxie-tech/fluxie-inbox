# Encerramento, parte 3: encerramento automático configurável

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** o encerramento por inatividade passa a ser configurável na tela Encerramento, com tempo, aviso ao cliente, espera depois do aviso, despedida e a opção de contar só o horário de atendimento, como no Zendesk, no Chatwoot e no Intercom.

**Architecture:**
- **Respostas automáticas:** `messages.auto_reply` (bool) marca o que o sistema responde sozinho: a resposta fora do horário, o aviso e a despedida. Essas mensagens não contam como "nossa última mensagem".
- **Configuração:** fica em `App\Settings\AutoCloseSettings`.
- **Decisão:** `App\Services\AutoClose\AutoCloseClock` decide, para um chamado e um instante, se é hora de avisar, de encerrar ou de nada. Usa `BusinessHours` e não grava nada.
- **Rotina:** `app:close-inactive-tickets` passa a rodar a cada minuto. Ela filtra os candidatos no banco pelo tempo corrido, pergunta ao relógio e manda o aviso ou encerra pela ação `CloseTicket`. O limite é de 30 envios de cada tipo por rodada.
- **Marca do aviso:** `tickets.auto_close_warned_at` guarda quando o aviso saiu.
- **Tela:** um componente filho `Settings\AutoClose`, dentro da tela Encerramento.

**Tech Stack:** Laravel 13 (scheduler, Cache::lock), Livewire 4.4, Flux 2 (free), PostgreSQL 18, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-26-encerramento-atendimento-design.md` (Parte 3, com as decisões do dono no fim da seção).

## Global Constraints

- **Padrão**, que vale também para instalação atualizada:

  | Chave | Valor | Faixa permitida |
  |-------|-------|-----------------|
  | ligado | sim | — |
  | X, tempo parado | 1440 min (24 h) | 15 min a 30 dias (43200 min) |
  | aviso | desligado | — |
  | Y, espera depois do aviso | 60 min | 15 min a 7 dias (10080 min) |
  | despedida | desligada | — |
  | contar só o horário de atendimento | desligado | — |

  Nenhuma mensagem nova chega ao cliente até o admin mexer.
- **Quem entra na regra:**
  - Só chamados `pendente` ou `em_atendimento`. `aguardando` (na fila) nunca entra.
  - `pendente` com `BotSession` ativa e `expires_at` não nulo fica de fora; o prazo da Automação vence primeiro (decisão do dono).
  - Só entra quando a última mensagem "que conta" é nossa: atendente (`painel`, `fora_do_painel`) ou Automação (`bot` sem `auto_reply`).
  - Mensagem do cliente conta como dele. Mensagem com `auto_reply = true` não conta.
- **Tempo:**
  - X conta a partir do `sent_at` da última mensagem que conta. Com "só o horário de atendimento", usa `BusinessHours::addBusinessSeconds`; sem nenhuma faixa cadastrada, conta o tempo corrido.
  - O aviso não zera o relógio. Y conta a partir de `auto_close_warned_at`, com a mesma regra de horário que X.
  - A marca só vale se for posterior ao `sent_at` da última mensagem que conta; não precisa ser limpa.
- **Envio:**
  - O aviso e a despedida só saem com `BusinessHours::isOpen(now)`. Sem faixa cadastrada, `isOpen` é sempre verdadeiro.
  - Se vencer fora do horário, saem na primeira rodada dentro do horário.
  - Com a despedida ligada, o encerramento também espera o horário: primeiro encerra, depois manda a despedida.
  - Com a despedida desligada, encerra quando vencer, a qualquer hora.
- **Canal parado ou desativado** (`channel.status !== WORKING` ou `channel.active = false`): não sai aviso nem despedida. O chamado encerra em X, como se o aviso estivesse desligado.
- **Textos:**
  - Aceitam `{{nome}}` (`Contact::label()`) e `{{protocolo}}` e têm de 1 a 500 caracteres.
  - Saem sem a assinatura do atendente: autor nulo e origem `bot`.
  - Aviso padrão: `Olá, {{nome}}! Ainda está por aí? Se não precisar de mais nada, vamos encerrar o atendimento {{protocolo}} em breve.`
  - Despedida padrão: `Encerramos o atendimento {{protocolo}} por falta de resposta. Se precisar, é só mandar uma mensagem por aqui.`
- **Rodada:**
  - Até 30 avisos e 30 despedidas por rodada (constante). O resto fica para as rodadas seguintes.
  - Cada chamado é tratado com `Cache::lock('bot-ticket-'.$id, 120)`, como o `ExpireBotSessionsCommand`, e com a linha travada.
  - As condições são conferidas de novo antes de agir.
  - A marca do aviso é gravada com `UPDATE ... WHERE` idempotente antes de criar a mensagem.
  - A mensagem sai pelo `OutgoingMessages::create` e pelo `SendTicketMessageJob`, fora da transação.
- **Encerramento:** `CloseTicket` com `ClosedReason::INATIVIDADE` e sem motivo nem resumo. Ele fecha a `BotSession`.
- **Desligado:** nada é avisado nem encerrado.
- **Agendamento:** `routes/console.php` passa a usar `->everyMinute()->withoutOverlapping(10)`. A opção `--hours` sai do comando.
- **Tela:** só admin. As ações chamam `authorize('admin')`. Acoplamento (PHPMD) de no máximo 13 por classe, sem baseline. Textos em português.
- **Desvio (dono, 26/11):** a conferência visual fica para o staging, depois da pré-release. Testes com `DB_PORT=5433`.

## Review Focus

1. **Cliente responde ao aviso:** a última mensagem que conta passa a ser dele, e o chamado nunca encerra. Se depois o atendente responde e volta a ficar parado, sai um aviso novo, porque a marca velha é anterior à resposta. Teste na Tarefa 3.
2. **O eco `fromMe` do aviso ou da despedida chega pelo webhook:** ele não pode virar mensagem que conta nem zerar o relógio. O eco é adotado pela linha `bot` dentro dos 600 s, e o `StoreInbound` de um eco não adotado, que vira `fora_do_painel`, conta como atendente. Teste na Tarefa 4: o eco adotado mantém `auto_reply`.
3. **Admin baixa X de 24 h para 15 min com 200 chamados parados:** saem 30 avisos por rodada, não 200 de uma vez. Teste na Tarefa 4.
4. **Rotina rodando duas vezes ao mesmo tempo, ou repetida:** um aviso só por chamado e um encerramento só. Teste na Tarefa 4.
5. **Fim de semana com "só o horário de atendimento":** um chamado parado na sexta às 17h, com X = 2 h e expediente das 8h às 18h, só recebe o aviso na segunda às 9h. Teste na Tarefa 2.

---

### Task 1: marcar as respostas automáticas e achar a última mensagem que conta

**Files:**
- Create: `database/migrations/2026_11_26_000003_add_auto_reply_to_messages_and_warned_at_to_tickets.php`
  - `messages.auto_reply`: boolean, não nulo, padrão `false`.
  - `tickets.auto_close_warned_at`: timestamp nulo.
  - Antes de terminar, a migração marca `auto_reply = true` nas mensagens `bot` antigas que são a resposta fora do horário. A regra: o texto é igual ao `body` da mensagem `bot` que sai logo depois de um evento `after_hours_reply` do mesmo ticket. Se casar com segurança for caro, deixar sem marcar e registrar no relatório o custo: um chamado antigo parado na fila já não entra na regra, por causa do status.
- Modify:
  - `app/Models/Message.php`: cast `auto_reply` boolean, `@property`, fillable.
  - `app/Models/Ticket.php`: cast `auto_close_warned_at` datetime e `@property`.
  - `app/Services/Inbox/AfterHoursReply.php`: a mensagem criada grava `auto_reply = true`. Aceitar `$autoReply` em `OutgoingMessages::create`/`record` como parâmetro nomeado opcional `bool $autoReply = false`.
- Create: `app/Services/AutoClose/LastCountingMessage.php`
- Test:
  - `tests/Feature/AutoClose/LastCountingMessageTest.php`
  - `tests/Feature/Inbox/AfterHoursReplyTest.php` (o assert de `auto_reply`)

**Interfaces:**
- Produces:
  - `OutgoingMessages::create(Ticket $ticket, ?User $author, string $text, MessageOrigin $origin = MessageOrigin::PAINEL, ?Message $quoted = null, bool $autoReply = false): Message`.
  - `LastCountingMessage::for(Ticket $ticket): ?Message`: a mensagem mais recente do ticket, por `sent_at` e depois `id`, entre as que não são `auto_reply`. Mensagem revogada conta igual.
  - `LastCountingMessage::isOurs(Message $m): bool`: `direction === OUT`.

- [ ] **Step 1: Testes que falham**

```php
public function test_resposta_fora_do_horario_grava_auto_reply(): void
public function test_a_ultima_que_conta_ignora_a_resposta_automatica(): void      // cliente 10:00, auto_reply 10:00:05 => for() = a do cliente; isOurs false
public function test_mensagem_da_automacao_conta_como_nossa(): void               // OUT origin bot sem auto_reply depois do cliente => isOurs true
public function test_mensagem_do_atendente_pelo_celular_conta_como_nossa(): void  // fora_do_painel
public function test_ticket_sem_mensagem_devolve_nulo(): void
```

- [ ] **Step 2: Rodar e ver falhar.** Comando: `DB_PORT=5433 php artisan test --filter='LastCountingMessageTest|AfterHoursReplyTest'`. Esperado: FAIL.
- [ ] **Step 3: Implementar** as assinaturas acima.
- [ ] **Step 4: Rodar e ver passar.** Mesmo comando do Step 2, mais `OutgoingMessages|SendTicketMessageTest`. Esperado: PASS.
- [ ] **Step 5: Commit.** Mensagem: `feat: respostas automáticas marcadas e a última mensagem que conta`.

### Task 2: configurações e o relógio do encerramento automático

**Files:**
- Create:
  - `app/Settings/AutoCloseSettings.php`
  - `app/Services/AutoClose/AutoCloseClock.php`
  - `app/Services/AutoClose/AutoCloseStep.php` (enum `WARN`, `CLOSE`, `NONE`)
- Test:
  - `tests/Feature/AutoClose/AutoCloseSettingsTest.php`
  - `tests/Feature/AutoClose/AutoCloseClockTest.php`

**Interfaces:**
- Consumes: `LastCountingMessage` (Tarefa 1) e `BusinessHours`.
- Produces:
  - **`AutoCloseSettings`**, estática, no molde de `CallSettings`, com chaves `auto_close.*`:
    - leitura: `enabled(): bool`, `idleMinutes(): int`, `warnEnabled(): bool`, `warnMessage(): string`, `closeAfterWarnMinutes(): int`, `farewellEnabled(): bool`, `farewellMessage(): string` e `businessHoursOnly(): bool`;
    - `save(array $values): void` grava só as chaves presentes;
    - constantes `DEFAULT_WARN`, `DEFAULT_FAREWELL`, `MIN_MINUTES = 15`, `MAX_IDLE_MINUTES = 43200`, `MAX_AFTER_WARN_MINUTES = 10080` e `MAX_MESSAGE = 500`;
    - valor fora da faixa ou de tipo errado volta ao padrão.
  - **`AutoCloseSettings::render(string $template, Ticket $ticket): string`:** troca `{{nome}}` e `{{protocolo}}`. Texto vazio volta ao padrão.
  - **`AutoCloseClock::step(Ticket $ticket, CarbonInterface $now, bool $canMessage): AutoCloseStep`.** `$canMessage` é falso com o canal parado ou desativado. A regra, em ordem:
    1. Desligado, status fora de `pendente`/`em_atendimento`, `pendente` com sessão ativa com `expires_at`, sem última que conta ou última que conta do cliente: `NONE`.
    2. `$deadline = due(última->sent_at, X)`.
    3. Marca do aviso válida (`warned_at > última->sent_at`): `$closeAt = due(warned_at, Y)`. Se `$now >= $closeAt`, `CLOSE`, ou `NONE` quando a despedida está ligada e `! isOpen($now)`. Se ainda não, `NONE`.
    4. Sem marca válida e com aviso ligado e `$canMessage`: `$now >= $deadline && isOpen($now)` dá `WARN`, senão `NONE`.
    5. Sem aviso, ou sem `$canMessage`: `$now >= $deadline` dá `CLOSE`, ou `NONE` quando a despedida está ligada, `$canMessage` é verdadeiro e `! isOpen($now)`. Senão, `NONE`.
    - `due($from, $minutes)` usa `addBusinessSeconds` com "só o horário" ligado, senão `$from + $minutes`.

- [ ] **Step 1: Testes que falham** (`AutoCloseClockTest`, usando `travelTo` só para fixar o `$now` passado ao método e nunca para simular expiração de cache)

```php
public function test_padrao_encerra_em_24h_sem_aviso(): void
public function test_desligado_nada(): void
public function test_aguardando_nunca_entra(): void
public function test_pendente_com_prazo_da_automacao_fica_de_fora_e_sem_prazo_entra(): void
public function test_ultima_do_cliente_nada_e_resposta_automatica_nao_conta(): void
public function test_aviso_sai_no_prazo_e_so_no_horario(): void
public function test_encerra_y_depois_do_aviso_e_marca_velha_nao_vale(): void        // Review Focus 1
public function test_so_horario_de_atendimento_pula_o_fim_de_semana(): void          // Review Focus 5: sexta 17h, X=120 => aviso segunda 9h
public function test_despedida_ligada_espera_o_horario_e_desligada_encerra_a_qualquer_hora(): void
public function test_canal_parado_encerra_em_x_sem_aviso(): void
// AutoCloseSettingsTest
public function test_padroes_e_valores_fora_da_faixa_voltam_ao_padrao(): void
public function test_render_troca_nome_e_protocolo_e_texto_vazio_usa_o_padrao(): void
```

- [ ] **Step 2: Rodar e ver falhar.** Comando: `DB_PORT=5433 php artisan test --filter='AutoCloseClockTest|AutoCloseSettingsTest'`. Esperado: FAIL.
- [ ] **Step 3: Implementar** as assinaturas acima.
- [ ] **Step 4: Rodar e ver passar.** Mesmo comando do Step 2. Esperado: PASS.
- [ ] **Step 5: Commit.** Mensagem: `feat: configurações e relógio do encerramento automático`.

### Task 3: a rotina avisa e encerra

**Files:**
- Modify:
  - `app/Console/Commands/CloseInactiveTickets.php` (reescrita; sai `--hours`)
  - `routes/console.php:14` (`everyMinute()`)
- Create: `app/Services/AutoClose/AutoCloseRun.php`, que concentra o trabalho para o comando ficar fino.
- Test: `tests/Feature/Console/CloseInactiveTicketsTest.php`, reescrito. Os casos de hoje que continuam válidos permanecem.

**Interfaces:**
- Consumes: `AutoCloseClock`, `AutoCloseSettings`, `LastCountingMessage`, `OutgoingMessages::create(..., autoReply: true)`, `SendTicketMessageJob` e `CloseTicket`.
- Produces: **`AutoCloseRun::__invoke(): array{warned: int, closed: int}`.**
  - Os candidatos vêm do banco: status `pendente` ou `em_atendimento`, em ordem de id, limitados a 500 por rodada (constante).
  - Pré-filtro: a última mensagem que conta é mais velha que `now - min(X, quando a marca existe: Y)`, calculado em SQL. Pode usar `greatest(last_inbound_at, last_outbound_at, opened_at)` mais a última `bot` sem `auto_reply`, ou subconsulta em `messages`.
  - Para cada candidato, com o lock, o passo vem do relógio:
    - `WARN`: a marca é gravada com `update ... where auto_close_warned_at is null or auto_close_warned_at <= :ultima`. Se afetou uma linha, a mensagem é criada com `auto_reply: true` e o job vai para a fila.
    - `CLOSE`: `CloseTicket(INATIVIDADE)`. Se fechou e a despedida está ligada com `$canMessage`, a despedida é criada e o job vai para a fila.
  - Para de avisar depois de 30 avisos e de mandar despedida depois de 30 despedidas. Encerramento sem despedida não tem limite.
  - O comando imprime `Avisos: {n}. Encerrados: {m}.`

- [ ] **Step 1: Testes que falham**

```php
public function test_esta_agendado_a_cada_minuto(): void
public function test_padrao_encerra_parado_ha_24h_cuja_ultima_e_nossa_e_fecha_a_sessao_do_bot(): void
public function test_aviso_ligado_manda_o_aviso_marca_e_encerra_depois_de_y_com_despedida(): void
public function test_cliente_responde_ao_aviso_e_nao_encerra(): void
public function test_aguardando_na_fila_nunca_recebe_aviso_nem_fecha(): void
public function test_canal_parado_nao_manda_nada_e_encerra_em_x(): void
public function test_limite_de_30_avisos_por_rodada(): void            // Review Focus 3: 35 candidatos => 30 avisos, a rodada seguinte manda 5
public function test_rodar_de_novo_nao_duplica_aviso_nem_encerramento(): void // Review Focus 4
public function test_texto_do_aviso_preenche_nome_e_protocolo_e_sai_sem_assinatura(): void
```

- [ ] **Step 2: Rodar e ver falhar.** Comando: `DB_PORT=5433 php artisan test --filter=CloseInactiveTicketsTest`. Esperado: FAIL.
- [ ] **Step 3: Implementar** o que está nas Interfaces.
- [ ] **Step 4: Rodar e ver passar.** Mesmo comando do Step 2, depois a suíte inteira, porque o agendamento e o `AfterHoursReply` mudam. Esperado: PASS.
- [ ] **Step 5: Commit.** Mensagem: `feat: rotina do encerramento automático com aviso, despedida e limite por rodada`.

### Task 4: o eco do aviso e a tela

**Files:**
- Modify: `app/Services/Inbox/PanelEcho.php`, só se o teste mostrar que é preciso. O eco adotado mantém a linha `bot` com `auto_reply`, porque a adoção só completa `wa_message_id`.
- Create:
  - `app/Livewire/Settings/AutoClose.php`
  - `resources/views/livewire/settings/auto-close.blade.php`
- Modify: `resources/views/livewire/settings/closing.blade.php`, que inclui `<livewire:settings.auto-close />` como segundo painel.
- Test:
  - `tests/Feature/AutoClose/AutoCloseEchoTest.php`
  - `tests/Feature/Livewire/AutoClosePageTest.php`

**Interfaces:**
- Consumes: `AutoCloseSettings`, com `render` para a prévia.
- Produces: **`Settings\AutoClose`.**
  - Propriedades públicas:
    - `bool $enabled`
    - `int $idleHours` e `int $idleMinutes` (X em horas e minutos)
    - `bool $warnEnabled` e `string $warnMessage`
    - `int $afterWarnHours` e `int $afterWarnMinutes`
    - `bool $farewellEnabled` e `string $farewellMessage`
    - `bool $businessHoursOnly`
  - **`save(): void`** valida e grava, com `authorize('admin')`. Mensagens:
    - "O tempo vai de 15 minutos a 30 dias."
    - "A espera vai de 15 minutos a 7 dias."
    - "O texto passa de 500 caracteres."
  - As prévias usam um ticket de exemplo: nome "Maria" e protocolo "2026-000123".
  - **Texto da tela:**
    - título do painel: "Encerramento automático";
    - subtítulo: "Encerra o chamado parado quando a última mensagem foi nossa. Chamado na fila e cliente esperando resposta nunca encerram sozinhos.";
    - ajuda do horário: "Conta só o tempo dentro do horário de atendimento; o aviso e a despedida também só saem nesse horário.";
    - aviso salvo: "Encerramento automático salvo."

- [ ] **Step 1: Testes que falham**

```php
// AutoCloseEchoTest
public function test_eco_do_aviso_adotado_mantem_auto_reply_e_nao_conta(): void // Review Focus 2
// AutoClosePageTest
public function test_abre_com_os_padroes(): void
public function test_salva_e_mostra_a_previa_com_nome_e_protocolo(): void
public function test_tempo_fora_da_faixa_e_texto_longo_sao_recusados(): void
public function test_quem_nao_e_admin_nao_salva_nem_forjando_a_chamada(): void
public function test_a_tela_encerramento_mostra_o_painel(): void
```

- [ ] **Step 2: Rodar e ver falhar.** Comando: `DB_PORT=5433 php artisan test --filter='AutoCloseEchoTest|AutoClosePageTest'`. Esperado: FAIL.
- [ ] **Step 3: Implementar** o que está nas Interfaces.
- [ ] **Step 4: Rodar e ver passar.** Mesmo comando do Step 2. Depois o portão parcial: Pint, PHPStan, PHPMD, `node --test tests/js` e `npm run build`. Esperado: tudo com saída 0.
- [ ] **Step 5: Commit.** Mensagem: `feat: painel do encerramento automático na tela Encerramento`.

---

Depois das tarefas vêm a revisão do branch (`revisor-final`) e o portão 7. A conferência visual e o teste real (aviso e despedida chegando no número de teste) ficam para o staging, depois da pré-release.
