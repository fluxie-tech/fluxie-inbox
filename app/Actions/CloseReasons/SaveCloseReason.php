<?php

namespace App\Actions\CloseReasons;

use App\Models\CloseReason;
use App\Rules\NoControlChars;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Regras dos motivos de encerramento: nome válido e único, sempre um motivo ativo e apagar só o que nunca foi usado. */
class SaveCloseReason
{
    public const NAME_LIMIT = 60;

    private const DUPLICATE = 'Já existe um motivo com esse nome.';

    private const IN_USE = 'Não dá para apagar: o motivo já foi usado em chamados. Desative-o para tirá-lo do Encerrar.';

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
        try {
            DB::transaction(function () use ($reason): void {
                $this->assertAnotherActive($reason);

                if ($reason->isUsed()) {
                    throw new CloseReasonRejected(self::IN_USE);
                }

                $reason->delete();
            });
        } catch (QueryException $e) {
            // Um chamado foi encerrado com este motivo entre a conferência e o delete: a chave estrangeira (23503) barrou.
            throw $e->getCode() === '23503' ? new CloseReasonRejected(self::IN_USE) : $e;
        }
    }

    /**
     * Trava todos os ativos, o próprio inclusive e sempre na mesma ordem (FOR UPDATE): dois admins desativando os dois últimos
     * ao mesmo tempo não se enroscam; o segundo espera e vê que sobrou um só.
     */
    private function assertAnotherActive(CloseReason $reason): void
    {
        $active = CloseReason::query()->active()->orderBy('id')->lockForUpdate()->get();

        if ($active->count() === 1 && $active->contains('id', $reason->id)) {
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
