<?php

namespace App\Models;

use App\Enums\HolidayKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Data de feriado. Fecha o atendimento o dia todo só quando `closes` é verdadeiro (a empresa fecha naquele dia).
 *
 * @property int $id
 * @property CarbonImmutable $date
 * @property string $name
 * @property HolidayKind $kind
 * @property bool $closes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['date', 'name', 'kind', 'closes'])]
class Holiday extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date' => 'date', 'kind' => HolidayKind::class, 'closes' => 'boolean'];
    }
}
