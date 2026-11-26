<?php

namespace App\Livewire\Settings;

use App\Actions\CloseReasons\CloseReasonRejected;
use App\Actions\CloseReasons\SaveCloseReason;
use App\Models\CloseReason;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Os motivos do Encerrar: criar, renomear, pedir resumo, ativar, ordenar e apagar o que nunca foi usado. */
#[Title('Encerramento')]
class Closing extends Component
{
    public string $newName = '';

    public function mount(): void
    {
        $this->authorize('admin');
    }

    public function create(string $name, SaveCloseReason $save): void
    {
        $this->authorize('admin');
        $this->resetErrorBag();

        $this->attempt('newName', function () use ($save, $name): void {
            $save->create($name);
            $this->newName = '';
        });
    }

    public function rename(int $id, string $name, SaveCloseReason $save): void
    {
        $this->authorize('admin');
        $this->resetErrorBag();

        $this->attempt('rename.'.$id, fn () => $save->rename(CloseReason::query()->findOrFail($id), $name));
    }

    public function toggleSummary(int $id): void
    {
        $this->authorize('admin');

        $reason = CloseReason::query()->findOrFail($id);
        $reason->update(['requires_summary' => ! $reason->requires_summary]);
    }

    public function setActive(int $id, bool $active, SaveCloseReason $save): void
    {
        $this->authorize('admin');
        $this->resetErrorBag();

        $this->attempt('active.'.$id, fn () => $save->setActive(CloseReason::query()->findOrFail($id), $active));
    }

    public function remove(int $id, SaveCloseReason $save): void
    {
        $this->authorize('admin');
        $this->resetErrorBag();

        $this->attempt('remove.'.$id, fn () => $save->delete(CloseReason::query()->findOrFail($id)));
    }

    public function move(int $id, string $direction): void
    {
        $this->authorize('admin');

        DB::transaction(function () use ($id, $direction): void {
            $ids = CloseReason::query()->ordered()->lockForUpdate()->pluck('id')->values()->all();
            $index = array_search($id, $ids, true);

            if ($index === false) {
                return;
            }

            $target = $direction === 'up' ? $index - 1 : $index + 1;

            if (! isset($ids[$target])) {
                return;
            }

            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

            // Renumera de 1 a n: a sequência fica contínua mesmo que tenha nascido com saltos.
            foreach ($ids as $position => $reasonId) {
                CloseReason::query()->whereKey($reasonId)->update(['position' => $position + 1]);
            }
        });
    }

    private function attempt(string $errorKey, callable $action): void
    {
        try {
            $action();
        } catch (CloseReasonRejected $e) {
            $this->addError($errorKey, $e->getMessage());
        }
    }

    public function render(): View
    {
        return view('livewire.settings.closing', ['reasons' => CloseReason::query()->ordered()->get()]);
    }
}
