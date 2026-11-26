<?php

namespace App\Actions\CloseReasons;

use App\Models\CloseReason;
use App\Rules\NoControlChars;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Regras dos motivos de encerramento: nome válido e único, sempre um motivo ativo e apagar só o que nunca foi usado. */
class SaveCloseReason
{
    public const NAME_LIMIT = 60;

    private const DUPLICATE = 'Já existe um motivo com esse nome.';

    private const LAST_ACTIVE = 'Mantenha pelo menos um motivo ativo.';

    /** @throws CloseReasonRejected */
    public function create(string $name): CloseReason
    {
        $name = $this->validName($name, null);

        return $this->guardDuplicate(fn (): CloseReason => DB::transaction(fn (): CloseReason => CloseReason::query()->create([
            'name' => $name,
            'position' => (CloseReason::query()->orderByDesc('position')->first()->position ?? 0) + 1,
        ])->refresh()));
    }

    /** @throws CloseReasonRejected */
    public function rename(CloseReason $reason, string $name): void
    {
        $name = $this->validName($name, $reason->id);

        $this->guardDuplicate(fn () => $reason->update(['name' => $name]));
    }

    /** @throws CloseReasonRejected */
    public function setActive(CloseReason $reason, bool $active): void
    {
        DB::transaction(function () use ($reason, $active): void {
            if (! $active) {
                $this->assertAnotherActive($reason);
            }

            $reason->update(['active' => $active]);
        });
    }

    /** @throws CloseReasonRejected */
    public function delete(CloseReason $reason): void
    {
        DB::transaction(function () use ($reason): void {
            $this->assertAnotherActive($reason);

            if ($reason->isUsed()) {
                throw new CloseReasonRejected('Não dá para apagar: o motivo já foi usado em chamados. Desative-o para tirá-lo do Encerrar.');
            }

            $reason->delete();
        });
    }

    /** Trava os ativos (FOR UPDATE): dois admins desativando motivos diferentes ao mesmo tempo não zeram a lista. */
    private function assertAnotherActive(CloseReason $reason): void
    {
        $others = CloseReason::query()->active()->whereKeyNot($reason->id)->lockForUpdate()->get();

        if ($reason->active && $others->isEmpty()) {
            throw new CloseReasonRejected(self::LAST_ACTIVE);
        }
    }

    private function validName(string $name, ?int $ignoreId): string
    {
        $name = trim($name);

        $error = Validator::make(['name' => $name], [
            'name' => ['required', 'string', 'max:'.self::NAME_LIMIT, new NoControlChars],
        ], [
            'name.required' => 'Informe o nome do motivo.',
            'name.max' => 'O nome passa de '.self::NAME_LIMIT.' caracteres.',
        ], ['name' => 'nome'])->errors()->first('name');

        if ($error !== '') {
            throw new CloseReasonRejected($error);
        }

        if (CloseReason::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            throw new CloseReasonRejected(self::DUPLICATE);
        }

        return $name;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $save
     * @return T
     */
    private function guardDuplicate(callable $save): mixed
    {
        try {
            return $save();
        } catch (UniqueConstraintViolationException) {
            // Outro admin gravou o mesmo nome entre a conferência e o insert: o índice barrou.
            throw new CloseReasonRejected(self::DUPLICATE);
        }
    }
}
