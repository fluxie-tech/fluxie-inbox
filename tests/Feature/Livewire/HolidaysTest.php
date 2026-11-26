<?php

namespace Tests\Feature\Livewire;

use App\Enums\HolidayKind;
use App\Enums\UserRole;
use App\Livewire\Settings\Holidays;
use App\Models\Holiday;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        Holiday::create(['date' => '2026-12-25', 'name' => 'Natal', 'kind' => HolidayKind::NACIONAL, 'closes' => true]);
        Holiday::create(['date' => '2026-12-24', 'name' => 'Véspera', 'kind' => HolidayKind::FACULTATIVO, 'closes' => false]);

        Livewire::actingAs($this->admin())->test(Holidays::class)
            ->assertSee('Nacional')
            ->assertSee('Facultativo')
            ->assertSee('25/12/2026')
            ->assertSee('sex')
            ->assertSee('qui')
            ->assertSeeHtml('wire:key="holiday-'.Holiday::query()->first()?->id.'"')
            ->assertSee('A empresa fecha');
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
