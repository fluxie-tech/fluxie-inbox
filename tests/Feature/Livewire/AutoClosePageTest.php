<?php

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Settings\AutoClose;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use App\Settings\AutoCloseSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class AutoClosePageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    public function test_abre_com_os_padroes(): void
    {
        Livewire::actingAs($this->admin())->test(AutoClose::class)
            ->assertSet('enabled', true)
            ->assertSet('idleHours', 24)
            ->assertSet('idleMinutes', 0)
            ->assertSet('warnEnabled', false)
            ->assertSet('afterWarnHours', 1)
            ->assertSet('afterWarnMinutes', 0)
            ->assertSet('farewellEnabled', false)
            ->assertSet('businessHoursOnly', false)
            ->assertSet('warnMessage', AutoCloseSettings::DEFAULT_WARN)
            ->assertSee('Encerramento automático');
    }

    public function test_salva_e_mostra_a_previa_com_nome_e_protocolo(): void
    {
        Livewire::actingAs($this->admin())->test(AutoClose::class)
            ->set('enabled', false)
            ->set('idleHours', 2)
            ->set('idleMinutes', 30)
            ->set('warnEnabled', true)
            ->set('warnMessage', 'Oi {{nome}}, falta pouco para o {{protocolo}}.')
            ->set('afterWarnHours', 0)
            ->set('afterWarnMinutes', 45)
            ->set('farewellEnabled', true)
            ->set('businessHoursOnly', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Encerramento automático salvo.')
            ->assertSee('Oi Maria, falta pouco para o 2026-000123.');

        $this->assertFalse(AutoCloseSettings::enabled());
        $this->assertSame(150, AutoCloseSettings::idleMinutes());
        $this->assertTrue(AutoCloseSettings::warnEnabled());
        $this->assertSame('Oi {{nome}}, falta pouco para o {{protocolo}}.', AutoCloseSettings::warnMessage());
        $this->assertSame(45, AutoCloseSettings::closeAfterWarnMinutes());
        $this->assertTrue(AutoCloseSettings::farewellEnabled());
        $this->assertTrue(AutoCloseSettings::businessHoursOnly());
    }

    public function test_tempo_fora_da_faixa_e_texto_longo_sao_recusados(): void
    {
        $component = Livewire::actingAs($this->admin())->test(AutoClose::class)
            ->set('idleHours', 0)->set('idleMinutes', 14)
            ->set('afterWarnHours', 168)->set('afterWarnMinutes', 1)
            ->set('warnMessage', str_repeat('a', 501))
            ->set('farewellEnabled', true)->set('farewellMessage', '')
            ->call('save')
            ->assertHasErrors(['idleMinutes', 'afterWarnMinutes', 'warnMessage', 'farewellMessage'])
            ->assertSee('O tempo vai de 15 minutos a 30 dias.')
            ->assertSee('A espera vai de 15 minutos a 7 dias.')
            ->assertSee('O texto passa de 500 caracteres.');

        $component->set('idleHours', 720)->set('idleMinutes', 1)->call('save')->assertHasErrors('idleMinutes');
        $this->assertSame(1440, AutoCloseSettings::idleMinutes());
    }

    public function test_quem_nao_e_admin_nao_salva_nem_forjando_a_chamada(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]));
        $forged = new AutoClose;
        $forged->enabled = false;

        try {
            $forged->save();
            $this->fail('A chamada forjada passou.');
        } catch (AuthorizationException) {
            $this->assertTrue(AutoCloseSettings::enabled());
        }
    }

    public function test_campo_de_tempo_vazio_da_erro_de_faixa_e_nao_grava(): void
    {
        $this->actingAs($this->admin());

        // Chamada direta: o `set` do teste do Livewire manda cada valor numa requisição e perde o vazio.
        foreach (['idleHours' => 'idleMinutes', 'idleMinutes' => 'idleMinutes', 'afterWarnHours' => 'afterWarnMinutes', 'afterWarnMinutes' => 'afterWarnMinutes'] as $field => $error) {
            foreach (['', null] as $empty) {
                $component = new AutoClose;
                $component->enabled = false;
                $component->$field = $empty;

                try {
                    $component->save();
                    $this->fail("Salvou com {$field} vazio.");
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey($error, $e->errors());
                }
            }
        }

        $this->assertTrue(AutoCloseSettings::enabled());
    }

    public function test_limites_exatos_das_faixas(): void
    {
        $page = Livewire::actingAs($this->admin())->test(AutoClose::class);
        $page->set('idleHours', 0)->set('idleMinutes', 15)->call('save')->assertHasNoErrors();
        $this->assertSame(15, AutoCloseSettings::idleMinutes());

        $page->set('idleHours', 720)->set('idleMinutes', 0)->set('afterWarnHours', 168)->set('afterWarnMinutes', 0)->call('save')->assertHasNoErrors();
        $this->assertSame(43200, AutoCloseSettings::idleMinutes());
        $this->assertSame(10080, AutoCloseSettings::closeAfterWarnMinutes());

        $page->set('afterWarnMinutes', 1)->call('save')->assertHasErrors('afterWarnMinutes');
        $page->set('afterWarnMinutes', 0)->set('idleMinutes', 1)->call('save')->assertHasErrors('idleMinutes');
    }

    public function test_previa_da_despedida_vazia_usa_o_padrao_dela_e_nao_escreve_no_banco(): void
    {
        $tickets = Ticket::query()->count();
        $contacts = Contact::query()->count();

        Livewire::actingAs($this->admin())->test(AutoClose::class)
            ->set('farewellMessage', '')
            ->assertSee('Encerramos o atendimento 2026-000123 por falta de resposta.');

        $this->assertSame($tickets, Ticket::query()->count());
        $this->assertSame($contacts, Contact::query()->count());
    }

    public function test_texto_so_e_obrigatorio_com_a_chave_ligada_e_desligada_grava_o_padrao(): void
    {
        $page = Livewire::actingAs($this->admin())->test(AutoClose::class)
            ->set('warnEnabled', false)->set('warnMessage', '')
            ->set('farewellEnabled', false)->set('farewellMessage', '')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(AutoCloseSettings::DEFAULT_WARN, AutoCloseSettings::warnMessage());
        $this->assertSame(AutoCloseSettings::DEFAULT_FAREWELL, AutoCloseSettings::farewellMessage());

        $page->set('warnEnabled', true)->set('warnMessage', '')->call('save')->assertHasErrors('warnMessage');
        $page->set('warnEnabled', false)->set('warnMessage', str_repeat('a', 501))->call('save')->assertHasErrors('warnMessage');
    }

    public function test_a_tela_encerramento_mostra_o_painel(): void
    {
        $this->actingAs($this->admin())->get('/configuracoes/encerramento')->assertOk()
            ->assertSee('Encerramento automático')
            ->assertSee('Chamado na fila e cliente esperando resposta nunca encerram sozinhos.');
    }
}
