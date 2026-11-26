<?php

namespace Tests\Feature\Holidays;

use App\Actions\Holidays\ImportHolidays;
use App\Enums\HolidayKind;
use App\Models\BusinessHour;
use App\Models\Holiday;
use App\Services\Holidays\HolidayImportRow;
use App\Services\Inbox\BusinessHours;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportHolidaysTest extends TestCase
{
    use RefreshDatabase;

    public function test_grava_so_as_linhas_boas_e_novas_e_facultativo_nao_fecha(): void
    {
        $rows = [
            new HolidayImportRow('2027-01-01', 'Ano Novo', HolidayKind::NACIONAL, null, false),
            new HolidayImportRow('2027-02-10', 'Quarta de Cinzas', HolidayKind::FACULTATIVO, null, false),
            new HolidayImportRow('2027-03-01', 'Já existe', HolidayKind::NACIONAL, null, true),
            new HolidayImportRow(null, 'Ruim', null, 'Data inválida.', false),
        ];

        $saved = (new ImportHolidays)($rows);

        $this->assertSame(2, $saved);
        $this->assertSame(2, Holiday::query()->count());
        $this->assertTrue(Holiday::query()->whereDate('date', '2027-01-01')->firstOrFail()->closes);
        $cinzas = Holiday::query()->whereDate('date', '2027-02-10')->firstOrFail();
        $this->assertFalse($cinzas->closes);
        $this->assertSame(HolidayKind::FACULTATIVO, $cinzas->kind);
    }

    public function test_data_cadastrada_entre_a_previa_e_a_gravacao_e_pulada(): void
    {
        Holiday::query()->create(['date' => '2027-01-01', 'name' => 'Cadastrado à mão', 'kind' => HolidayKind::MANUAL, 'closes' => true]);

        $saved = (new ImportHolidays)([new HolidayImportRow('2027-01-01', 'Ano Novo', HolidayKind::NACIONAL, null, false)]);

        $this->assertSame(0, $saved);
        $this->assertSame('Cadastrado à mão', Holiday::query()->firstOrFail()->name);
    }

    public function test_feriado_facultativo_importado_nao_fecha_o_atendimento(): void
    {
        foreach ([1, 2, 3, 4, 5] as $weekday) {
            BusinessHour::create(['weekday' => $weekday, 'opens_at' => '08:00', 'closes_at' => '18:00']);
        }

        (new ImportHolidays)([new HolidayImportRow('2027-02-10', 'Quarta de Cinzas', HolidayKind::FACULTATIVO, null, false)]);

        $this->assertTrue((new BusinessHours)->isOpen(CarbonImmutable::parse('2027-02-10 10:00', 'America/Sao_Paulo')));
    }

    public function test_conta_so_o_que_gravou_quando_a_mesma_data_vem_duas_vezes(): void
    {
        $row = new HolidayImportRow('2027-01-01', 'Ano Novo', HolidayKind::NACIONAL, null, false);

        $this->assertSame(1, (new ImportHolidays)([$row, $row]));
        $this->assertSame(1, Holiday::query()->count());
        $this->assertNotNull(Holiday::query()->firstOrFail()->created_at);
    }
}
