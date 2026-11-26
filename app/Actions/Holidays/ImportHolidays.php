<?php

namespace App\Actions\Holidays;

use App\Enums\HolidayKind;
use App\Models\Holiday;
use App\Services\Holidays\HolidayImportRow;
use Illuminate\Support\Facades\DB;

/** Grava as linhas boas e novas da prévia numa transação. Só o facultativo entra sem fechar o atendimento. */
class ImportHolidays
{
    /**
     * @param  list<HolidayImportRow>  $rows
     * @return int quantas linhas foram gravadas
     */
    public function __invoke(array $rows): int
    {
        return DB::transaction(function () use ($rows): int {
            $saved = 0;

            foreach ($rows as $row) {
                // Confere de novo: a data pode ter sido cadastrada entre a prévia e a gravação.
                if ($row->error !== null || $row->exists || $row->date === null || $row->kind === null || Holiday::query()->whereDate('date', $row->date)->exists()) {
                    continue;
                }

                Holiday::query()->create([
                    'date' => $row->date,
                    'name' => $row->name,
                    'kind' => $row->kind,
                    'closes' => $row->kind !== HolidayKind::FACULTATIVO,
                ]);
                $saved++;
            }

            return $saved;
        });
    }
}
