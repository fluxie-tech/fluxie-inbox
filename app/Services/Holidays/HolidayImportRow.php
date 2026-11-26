<?php

namespace App\Services\Holidays;

use App\Enums\HolidayKind;

/** Uma linha do arquivo de feriados lida pela prévia: `error` nulo é linha boa, `exists` é data já cadastrada. */
final readonly class HolidayImportRow
{
    /** @param  ?string  $date  `Y-m-d`, ou nulo quando a data é inválida */
    public function __construct(
        public ?string $date,
        public string $name,
        public ?HolidayKind $kind,
        public ?string $error,
        public bool $exists,
    ) {}
}
