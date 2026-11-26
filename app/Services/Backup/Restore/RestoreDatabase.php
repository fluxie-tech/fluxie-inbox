<?php

namespace App\Services\Backup\Restore;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Restaura o banco ao lado e troca por renomeação (ver o spec). Antes da troca o sistema segue no ar e uma falha só apaga o
 * banco novo; a troca roda em manutenção e, se ela ou o `migrate` falharem, é desfeita. O estado nunca fica em manutenção.
 */
class RestoreDatabase
{
    /** Os passos de cada modo, na ordem: a tela usa os mesmos para mostrar o andamento antes de o job começar. */
    public const RESTORE_STEPS = ['inspect', 'safety', 'restore', 'verify', 'swap', 'migrate', 'resume', 'recover'];

    public const SWITCH_STEPS = ['swap', 'migrate', 'resume', 'recover'];

    public function __construct(
        private readonly RestoreSource $sources,
        private readonly Maintenance $maintenance,
        private readonly RestoreProgress $progress,
        private readonly PrepareRestore $prepare,
        private readonly Cutover $cutover,
        private readonly RestoreReport $report,
    ) {}

    /**
     * Falhas do processo viram estado `failed` no {@see RestoreProgress}; só o lock ocupado é lançado (a outra restauração é
     * a dona do andamento).
     *
     * @throws RestoreRejected
     */
    public function __invoke(RestoreRequest $request): void
    {
        $lock = Cache::lock('restore-database', 1800);

        if (! $lock->get()) {
            throw new RestoreRejected('Já há uma restauração em andamento.');
        }

        $run = new RestoreRun($request);

        try {
            $request->mode === 'switch' ? $this->switchBack($run) : $this->restore($run);
            $this->succeed($run);
        } catch (Throwable $e) {
            $this->fail($run, $e);
        } finally {
            $this->release($run, $lock);
        }
    }

    /** @throws Throwable */
    private function restore(RestoreRun $run): void
    {
        $this->progress->begin('Restaurando o banco', self::RESTORE_STEPS);
        $this->assertReady();
        $this->prepare->restore($run);
        ($this->cutover)($run);
    }

    /** @throws Throwable */
    private function switchBack(RestoreRun $run): void
    {
        $this->progress->begin('Voltando para o banco guardado', self::SWITCH_STEPS);
        $this->assertReady();
        $this->prepare->switchBack($run);
        ($this->cutover)($run);
    }

    private function succeed(RestoreRun $run): void
    {
        $message = $this->report->succeeded($run);

        $this->progress->finish($message);
        $this->report->log($run, 'ok', $message);
    }

    private function fail(RestoreRun $run, Throwable $e): void
    {
        $message = $this->report->publicMessage($e);

        try {
            $message = $this->cutover->undo($run, $message);
        } catch (Throwable $undoFailure) {
            report($undoFailure);
            // Sem volta: o sistema fica em manutenção (ninguém grava num banco meio migrado) até o admin conferir.
            $run->keepDown = true;
            $message .= ' Atenção: não foi possível desfazer a troca; o sistema foi deixado em manutenção. Confira o banco ativo e rode `php artisan up` quando estiver certo.';
        }

        $this->progress->fail($message);
        $this->report->log($run, 'falhou', $message);
    }

    /** @throws RestoreRejected */
    private function assertReady(): void
    {
        $problem = $this->maintenance->problem();

        if ($problem !== null) {
            throw new RestoreRejected($problem);
        }
    }

    private function release(RestoreRun $run, Lock $lock): void
    {
        try {
            $run->inMaintenance && ! $run->keepDown && $this->maintenance->leave();
        } catch (Throwable $e) {
            report($e);
        }

        // No `switch` a origem é o nome de um banco, não um arquivo: não há o que descartar.
        $run->request->mode === 'switch' || $this->sources->discard($run->request->source, $run->path);
        $lock->release();
    }
}
