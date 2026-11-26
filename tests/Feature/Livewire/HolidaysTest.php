<?php

namespace Tests\Feature\Livewire;

use App\Actions\Holidays\ImportHolidays;
use App\Enums\HolidayKind;
use App\Enums\UserRole;
use App\Livewire\Settings\Holidays;
use App\Models\Holiday;
use App\Models\User;
use App\Services\Holidays\HolidayImportParser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use Tests\TestCase;

class HolidaysTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    public function test_cadastra_e_remove_feriado_com_data_unica(): void
    {
        $component = Livewire::actingAs($this->admin())->test(Holidays::class)
            ->set('holidayDate', '2026-12-25')
            ->set('holidayName', 'Natal')
            ->call('addHoliday')
            ->assertHasNoErrors();

        $holiday = Holiday::query()->sole();
        $this->assertSame(HolidayKind::MANUAL, $holiday->kind);
        $this->assertTrue($holiday->closes);

        $component->set('holidayDate', '2026-12-25')->set('holidayName', 'Outro')->call('addHoliday')->assertHasErrors('holidayDate');
        $component->set('holidayDate', '')->set('holidayName', '')->call('addHoliday')->assertHasErrors(['holidayDate', 'holidayName']);

        $component->call('removeHoliday', $holiday->id);
        $this->assertSame(0, Holiday::query()->count());
    }

    public function test_liga_e_desliga_a_empresa_fecha(): void
    {
        $holiday = Holiday::create(['date' => '2026-12-25', 'name' => 'Natal', 'kind' => HolidayKind::NACIONAL, 'closes' => true]);
        $component = Livewire::actingAs($this->admin())->test(Holidays::class);

        $component->call('setCloses', $holiday->id, false);
        $this->assertFalse($holiday->refresh()->closes);

        $component->call('setCloses', $holiday->id, true);
        $this->assertTrue($holiday->refresh()->closes);
    }

    public function test_lista_mostra_tipo_e_chave(): void
    {
        $natal = Holiday::create(['date' => '2026-12-25', 'name' => 'Natal', 'kind' => HolidayKind::NACIONAL, 'closes' => true]);
        $vespera = Holiday::create(['date' => '2026-12-24', 'name' => 'Véspera', 'kind' => HolidayKind::FACULTATIVO, 'closes' => false]);

        $html = Livewire::actingAs($this->admin())->test(Holidays::class)
            ->assertSee('Nacional')
            ->assertSee('Facultativo')
            ->assertSee('25/12/2026')
            ->assertSee('sex')
            ->assertSee('qui')
            ->assertSeeHtml('wire:key="holiday-'.$natal->id.'"')
            ->assertSee('A empresa fecha')
            ->html();

        $this->assertSame(1, preg_match('/holiday-'.$natal->id.'".*?<\/li>/s', $html, $natalItem));
        $this->assertSame(1, preg_match('/holiday-'.$vespera->id.'".*?<\/li>/s', $html, $esperaItem));
        // `\s` antes de "checked": a classe do Flux tem `data-checked:bg-...`, que não conta.
        $this->assertMatchesRegularExpression('/<ui-switch[^>]*\schecked\s/', $natalItem[0]);
        $this->assertDoesNotMatchRegularExpression('/<ui-switch[^>]*\schecked\s/', $esperaItem[0]);
    }

    public function test_supervisor_nao_monta_o_componente(): void
    {
        Livewire::actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->test(Holidays::class)->assertForbidden();
    }

    public function test_o_prompt_so_aparece_com_os_quatro_campos_preenchidos(): void
    {
        $component = Livewire::actingAs($this->admin())->test(Holidays::class)
            ->set('promptCity', 'Ribeirão Preto')
            ->set('promptUf', 'sp')
            ->set('promptFrom', '2026-11-26')
            ->assertDontSee('entre 26/11/2026');

        $this->assertSame('SP', $component->get('promptUf'));

        $component->set('promptTo', '2027-12-31')->assertSee('Ribeirão Preto/SP')->assertSee('entre 26/11/2026 e 31/12/2027');
    }

    private function arquivo(): UploadedFile
    {
        $json = json_encode([
            ['data' => '2026-12-25', 'nome' => 'Natal', 'tipo' => 'nacional'],
            ['data' => '2026-12-24', 'nome' => 'Véspera de Natal', 'tipo' => 'facultativo'],
            ['data' => '2026-11-20', 'nome' => 'Consciência Negra', 'tipo' => 'nacional'],
            ['data' => 'xx', 'nome' => 'Quebrado', 'tipo' => 'nacional'],
        ]);

        return UploadedFile::fake()->createWithContent('feriados.json', $json);
    }

    public function test_enviar_o_arquivo_mostra_a_previa_sem_gravar(): void
    {
        Holiday::create(['date' => '2026-11-20', 'name' => 'Consciência', 'kind' => HolidayKind::MANUAL, 'closes' => true]);

        Livewire::actingAs($this->admin())->test(Holidays::class)
            ->set('importFile', $this->arquivo())
            ->assertSee('Véspera de Natal')
            ->assertSee('Já cadastrado')
            ->assertSee('Data inválida.')
            ->assertSee('2 novos, 1 já cadastrados, 1 com erro')
            ->assertSee('Importar 2 feriados');

        $this->assertSame(1, Holiday::query()->count());
    }

    public function test_importar_grava_e_avisa_quantos(): void
    {
        Holiday::create(['date' => '2026-11-20', 'name' => 'Consciência', 'kind' => HolidayKind::MANUAL, 'closes' => true]);

        Livewire::actingAs($this->admin())->test(Holidays::class)
            ->set('importFile', $this->arquivo())
            ->call('import')
            ->assertSee('2 feriados importados.')
            ->assertDontSee('Importar 2 feriados');

        $this->assertSame(3, Holiday::query()->count());
        $this->assertFalse(Holiday::query()->where('kind', HolidayKind::FACULTATIVO)->sole()->closes);
        $this->assertTrue(Holiday::query()->where('kind', HolidayKind::NACIONAL)->where('name', 'Natal')->sole()->closes);
    }

    public function test_arquivo_invalido_mostra_um_erro_e_nao_mostra_importar(): void
    {
        Livewire::actingAs($this->admin())->test(Holidays::class)
            ->set('importFile', UploadedFile::fake()->createWithContent('f.json', 'isto não é json'))
            ->assertSee('O arquivo não é uma lista JSON de feriados.')
            ->assertDontSee('Importar 0')
            ->assertDontSee('Cancelar');
    }

    public function test_arquivo_grande_ou_de_outro_tipo_e_recusado(): void
    {
        $component = Livewire::actingAs($this->admin())->test(Holidays::class)
            ->set('importFile', UploadedFile::fake()->create('f.json', 101))
            ->assertHasErrors('importFile')
            ->assertSee('Envie um arquivo .json de até 100 KB.');

        $component->set('importFile', UploadedFile::fake()->createWithContent('f.txt', '[]'))->assertHasErrors('importFile');
    }

    public function test_arquivo_enviado_que_sumiu_do_disco_temporario_mostra_aviso_e_nao_grava(): void
    {
        $component = Livewire::actingAs($this->admin())->test(Holidays::class)
            ->set('importFile', $this->arquivo());

        // A limpeza do `livewire-tmp` apaga o diretório inteiro depois de 24 h.
        Storage::disk(FileUploadConfiguration::disk())->deleteDirectory(FileUploadConfiguration::directory());

        $component->call('import')
            ->assertHasNoErrors()
            ->assertSee('O arquivo enviado expirou. Envie de novo.');

        $this->assertSame(0, Holiday::query()->count());
    }

    public function test_um_feriado_usa_o_singular_no_botao_e_no_aviso(): void
    {
        $um = UploadedFile::fake()->createWithContent('f.json', json_encode([['data' => '2026-12-25', 'nome' => 'Natal', 'tipo' => 'nacional']]));

        Livewire::actingAs($this->admin())->test(Holidays::class)
            ->set('importFile', $um)
            ->assertSee('Importar 1 feriado')
            ->assertDontSee('Importar 1 feriados')
            ->call('import')
            ->assertSee('1 feriado importado.')
            ->assertDontSee('1 feriados importados.');
    }

    public function test_o_prompt_limita_cidade_e_uf_no_servidor(): void
    {
        $component = Livewire::actingAs($this->admin())->test(Holidays::class)
            ->set('promptCity', '  '.str_repeat('A', 100).'  ')
            ->set('promptUf', 's1p9x')
            ->set('promptFrom', '2026-11-26')
            ->set('promptTo', '2027-12-31');

        $this->assertSame('SP', $component->get('promptUf'));
        $component->assertSee(str_repeat('A', 80).'/SP')->assertDontSee(str_repeat('A', 81));
    }

    public function test_o_prompt_exige_datas_reais_e_periodo_em_ordem(): void
    {
        $component = Livewire::actingAs($this->admin())->test(Holidays::class)
            ->set('promptCity', 'Ribeirão Preto')
            ->set('promptUf', 'SP')
            ->set('promptFrom', '2026-02-31')
            ->set('promptTo', '2027-12-31')
            ->assertDontSee('Ribeirão Preto/SP');

        $component->set('promptFrom', '2027-12-31')->set('promptTo', '2026-11-26')->assertDontSee('Ribeirão Preto/SP');
        $component->set('promptFrom', '2026-11-26')->set('promptTo', '2026-11-26')->assertSee('Ribeirão Preto/SP');
    }

    public function test_a_chave_empresa_fecha_tem_nome_acessivel_com_feriado_e_data(): void
    {
        Holiday::create(['date' => '2026-12-25', 'name' => 'Natal', 'kind' => HolidayKind::NACIONAL, 'closes' => true]);

        Livewire::actingAs($this->admin())->test(Holidays::class)
            ->assertSeeHtml('aria-label="A empresa fecha em Natal (25/12/2026)"');
    }

    public function test_updated_hooks_sao_so_do_admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]));

        $this->expectException(AuthorizationException::class);
        (new Holidays)->updatedPromptUf();
    }

    public function test_cancelar_limpa_a_previa(): void
    {
        Livewire::actingAs($this->admin())->test(Holidays::class)
            ->set('importFile', $this->arquivo())
            ->assertSee('Véspera de Natal')
            ->call('cancelImport')
            ->assertDontSee('Véspera de Natal')
            ->assertSet('importFile', null);
    }

    public function test_importar_e_admin_somente(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]));
        $forged = new Holidays;

        foreach ([
            fn (Holidays $c) => $c->import(new HolidayImportParser, new ImportHolidays),
            fn (Holidays $c) => $c->updatedImportFile(new HolidayImportParser),
            fn (Holidays $c) => $c->cancelImport(),
        ] as $call) {
            try {
                $call($forged);
                $this->fail('A chamada forjada passou.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_quem_nao_e_admin_nao_mexe_nem_forjando_a_chamada(): void
    {
        $holiday = Holiday::create(['date' => '2026-12-25', 'name' => 'Natal', 'kind' => HolidayKind::NACIONAL, 'closes' => true]);
        $this->actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]));
        $forged = new Holidays;
        $forged->holidayDate = '2026-12-26';
        $forged->holidayName = 'Forjado';

        foreach ([
            fn (Holidays $c) => $c->addHoliday(),
            fn (Holidays $c) => $c->removeHoliday($holiday->id),
            fn (Holidays $c) => $c->setCloses($holiday->id, false),
        ] as $call) {
            try {
                $call($forged);
                $this->fail('A chamada forjada passou.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, Holiday::query()->count());
        $this->assertTrue($holiday->refresh()->closes);
    }

    public function test_a_pagina_horario_mostra_o_componente_de_feriados(): void
    {
        Holiday::create(['date' => '2026-12-25', 'name' => 'Natal', 'kind' => HolidayKind::NACIONAL, 'closes' => true]);

        $this->actingAs($this->admin())->get('/configuracoes/horario')->assertOk()->assertSee('Feriados')->assertSee('Natal');
    }
}
