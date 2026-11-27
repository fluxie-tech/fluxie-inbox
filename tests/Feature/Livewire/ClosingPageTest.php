<?php

namespace Tests\Feature\Livewire;

use App\Actions\CloseReasons\SaveCloseReason;
use App\Enums\UserRole;
use App\Livewire\Settings\Closing;
use App\Models\CloseReason;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClosingPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    private function reason(string $name): CloseReason
    {
        return CloseReason::query()->where('name', $name)->firstOrFail();
    }

    public function test_so_admin_abre_e_mexe(): void
    {
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);

        $this->actingAs($supervisor)->get('/configuracoes/encerramento')->assertForbidden();
        Livewire::actingAs($supervisor)->test(Closing::class)->assertForbidden();
        $this->assertSame(3, CloseReason::query()->count());
    }

    public function test_supervisor_nao_muda_nada_nem_forjando_a_chamada(): void
    {
        $id = $this->reason('Resolvido')->id;
        $this->actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]));
        $save = new SaveCloseReason;

        foreach ([
            fn (Closing $c) => $c->create('Novo', $save),
            fn (Closing $c) => $c->rename($id, 'Outro', $save),
            fn (Closing $c) => $c->toggleSummary($id, true),
            fn (Closing $c) => $c->setActive($id, false, $save),
            fn (Closing $c) => $c->move($id, 'down'),
            fn (Closing $c) => $c->remove($id, $save),
        ] as $call) {
            try {
                $call(new Closing);
                $this->fail('A chamada forjada passou.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $reason = $this->reason('Resolvido');
        $this->assertSame([3, 1, false, true], [CloseReason::query()->count(), $reason->position, $reason->requires_summary, $reason->active]);
    }

    public function test_admin_ve_os_motivos(): void
    {
        $this->actingAs($this->admin())->get('/configuracoes/encerramento')->assertOk()->assertSee('Sem resposta');
    }

    public function test_pagina_usa_a_largura_de_formulario(): void
    {
        Livewire::actingAs($this->admin())->test(Closing::class)->assertSeeHtml('max-w-4xl');
    }

    public function test_cria_no_fim_da_lista(): void
    {
        Livewire::actingAs($this->admin())->test(Closing::class)->call('create', ' Orçamento enviado ')->assertHasNoErrors();

        $new = $this->reason('Orçamento enviado');
        $this->assertSame(4, $new->position);
        $this->assertTrue($new->active);
        $this->assertFalse($new->requires_summary);
    }

    public function test_nome_repetido_sem_diferenciar_maiusculas_e_recusado(): void
    {
        Livewire::actingAs($this->admin())->test(Closing::class)
            ->call('create', 'resolvido')
            ->assertHasErrors(['newName' => 'Já existe um motivo com esse nome.']);

        $this->assertSame(3, CloseReason::query()->count());
    }

    public function test_nome_vazio_longo_ou_com_caractere_de_controle_e_recusado(): void
    {
        $page = Livewire::actingAs($this->admin())->test(Closing::class);

        $page->call('create', '  ')->assertHasErrors(['newName' => 'Informe o nome do motivo.']);
        $page->call('create', str_repeat('a', 61))->assertHasErrors(['newName' => 'O nome passa de 60 caracteres.']);
        $page->call('create', "Mau\x07nome")->assertHasErrors('newName');
        $this->assertSame(3, CloseReason::query()->count());
    }

    public function test_renomear_muda_o_nome(): void
    {
        $id = $this->reason('Sem resposta')->id;
        $page = Livewire::actingAs($this->admin())->test(Closing::class);

        $page->call('rename', $id, 'Sumiu')->assertHasNoErrors();
        $this->assertSame('Sumiu', CloseReason::query()->findOrFail($id)->name);

        $page->call('rename', $id, 'RESOLVIDO')->assertHasErrors(['rename.'.$id => 'Já existe um motivo com esse nome.']);
        $page->call('rename', $id, 'sumiu')->assertHasNoErrors();
    }

    public function test_liga_e_desliga_pede_resumo(): void
    {
        $reason = $this->reason('Sem resposta');
        $page = Livewire::actingAs($this->admin())->test(Closing::class);

        $page->call('toggleSummary', $reason->id, true);
        $flipped = $reason->fresh();
        $this->assertNotNull($flipped);
        $page->call('toggleSummary', $reason->id, false);
        $this->assertNotSame($flipped->requires_summary, $reason->fresh()?->requires_summary);
    }

    public function test_desativar_o_ultimo_ativo_e_recusado(): void
    {
        $page = Livewire::actingAs($this->admin())->test(Closing::class);
        $page->call('setActive', $this->reason('Resolvido')->id, false)->call('setActive', $this->reason('Sem resposta')->id, false);

        $last = $this->reason('Cliente pediu');
        $page->call('setActive', $last->id, false)->assertHasErrors(['active.'.$last->id => 'Mantenha pelo menos um motivo ativo.']);

        $this->assertTrue($last->fresh()?->active);
    }

    public function test_reativar_volta_ao_encerrar(): void
    {
        $reason = $this->reason('Sem resposta');
        $page = Livewire::actingAs($this->admin())->test(Closing::class);

        $page->call('setActive', $reason->id, false);
        $this->assertNotContains($reason->id, CloseReason::query()->active()->pluck('id')->all());

        $page->call('setActive', $reason->id, true);
        $this->assertContains($reason->id, CloseReason::query()->active()->pluck('id')->all());
    }

    public function test_mover_troca_a_ordem_e_no_limite_nao_faz_nada(): void
    {
        $page = Livewire::actingAs($this->admin())->test(Closing::class);
        $names = fn (): array => CloseReason::query()->ordered()->pluck('name')->all();

        $page->call('move', $this->reason('Resolvido')->id, 'up');
        $this->assertSame(['Resolvido', 'Sem resposta', 'Cliente pediu'], $names());

        $page->call('move', $this->reason('Resolvido')->id, 'down');
        $this->assertSame(['Sem resposta', 'Resolvido', 'Cliente pediu'], $names());

        $page->call('move', $this->reason('Cliente pediu')->id, 'down');
        $this->assertSame(['Sem resposta', 'Resolvido', 'Cliente pediu'], $names());
    }

    public function test_apagar_motivo_usado_e_recusado_e_o_sem_uso_some(): void
    {
        $used = $this->reason('Resolvido');
        $ticket = Ticket::factory()->create();
        $ticket->forceFill(['close_reason_id' => $used->id])->save();
        $free = $this->reason('Cliente pediu');
        $page = Livewire::actingAs($this->admin())->test(Closing::class);
        $page->call('remove', $used->id)->assertHasErrors('remove.'.$used->id);
        // assertHasErrors corta a mensagem no ":" (a lê como regra:parâmetro); por isso a comparação é direta.
        $this->assertSame('Não dá para apagar: o motivo já foi usado em chamados. Desative-o para tirá-lo do Encerrar.', $page->errors()->first('remove.'.$used->id));
        $this->assertNotNull($used->fresh());

        $page->call('remove', $free->id)->assertHasNoErrors();
        $this->assertNull($free->fresh());
    }

    public function test_o_menu_mostra_encerramento_no_grupo_atendimento_so_para_admin(): void
    {
        $this->actingAs($this->admin())->get('/configuracoes/encerramento')->assertSee('Encerramento');
        $this->actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->get('/configuracoes/etiquetas')->assertDontSee(route('configuracoes.encerramento'));
    }

    public function test_o_erro_do_nome_aparece_uma_vez_so(): void
    {
        $html = Livewire::actingAs($this->admin())->test(Closing::class)->call('create', 'resolvido')->html();

        $this->assertSame(1, substr_count($html, 'Já existe um motivo com esse nome.'));
    }

    public function test_pede_resumo_vem_do_interruptor_e_nao_inverte_no_servidor(): void
    {
        $reason = $this->reason('Sem resposta');
        $page = Livewire::actingAs($this->admin())->test(Closing::class);

        $page->call('toggleSummary', $reason->id, true)->call('toggleSummary', $reason->id, true);
        $this->assertTrue($reason->fresh()?->requires_summary);

        $page->call('toggleSummary', $reason->id, false);
        $this->assertFalse($reason->fresh()?->requires_summary);
    }

    public function test_interruptor_recusado_e_redesenhado_para_refletir_o_servidor(): void
    {
        $page = Livewire::actingAs($this->admin())->test(Closing::class);
        $page->call('setActive', $this->reason('Resolvido')->id, false)->call('setActive', $this->reason('Sem resposta')->id, false);
        $last = $this->reason('Cliente pediu');
        $key = fn (): bool => str_contains($page->html(), 'wire:key="active-'.$last->id.'-0"');

        $this->assertTrue($key());
        $page->call('setActive', $last->id, false)->assertHasErrors('active.'.$last->id);
        $this->assertFalse($key());
    }

    public function test_setas_do_primeiro_e_do_ultimo_ficam_desativadas(): void
    {
        $html = Livewire::actingAs($this->admin())->test(Closing::class)->html();
        $disabled = fn (string $label): bool => preg_match('/<button[^>]*aria-label="'.$label.'"[^>]*>/', $html, $m) === 1 && preg_match('/\sdisabled[\s=>]/', $m[0]) === 1;

        $this->assertTrue($disabled('Subir Resolvido'));
        $this->assertFalse($disabled('Descer Resolvido'));
        $this->assertFalse($disabled('Subir Cliente pediu'));
        $this->assertTrue($disabled('Descer Cliente pediu'));
    }
}
