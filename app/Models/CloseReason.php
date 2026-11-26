<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CloseReasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Motivo de encerramento de atendimento, editável nos ajustes.
 *
 * @property int $id
 * @property string $name
 * @property bool $requires_summary
 * @property bool $active
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'requires_summary', 'active', 'position'])]
class CloseReason extends Model
{
    /** @use HasFactory<CloseReasonFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['requires_summary' => 'boolean', 'active' => 'boolean'];
    }

    /**
     * @param  Builder<CloseReason>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * @param  Builder<CloseReason>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }

    public function isUsed(): bool
    {
        return Ticket::query()->where('close_reason_id', $this->id)->exists();
    }
}
