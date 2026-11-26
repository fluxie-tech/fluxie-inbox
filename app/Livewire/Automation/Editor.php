<?php

namespace App\Livewire\Automation;

use App\Models\User;
use App\Models\Workflow;
use App\Services\Bot\EditorConfig;
use App\Services\Bot\FlowGraphShape;
use App\Services\Bot\InvalidFlowException;
use App\Services\Bot\Simulation\BotSimulator;
use App\Services\Bot\Simulation\SimulationState;
use App\Services\Bot\WorkflowPublisher;
use Closure;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * O editor visual de um workflow. O canvas vive no navegador (resources/js/flow-editor); aqui chega o grafo inteiro a
 * cada gravação, e o servidor confere a forma antes de guardar. Quem manda no que é publicável é o `FlowValidator`.
 *
 * @phpstan-import-type StateShape from SimulationState
 */
#[Layout('layouts::inbox')]
#[Title('Editor de workflow')]
class Editor extends Component
{
    private const SIM_TEXT_MAX = 1000;

    private const CLOCKS = ['real', 'aberto', 'fechado'];

    public Workflow $workflow;

    /**
     * A resposta da última ação (também devolvida ao navegador).
     *
     * @var array<string, mixed>
     */
    public array $result = [];

    private FlowGraphShape $shape;

    private EditorConfig $config;

    public function boot(FlowGraphShape $shape, EditorConfig $config): void
    {
        $this->shape = $shape;
        $this->config = $config;
    }

    public function mount(Workflow $workflow): void
    {
        $this->authorize('supervisor');

        $this->workflow = $workflow;
    }

    /**
     * A conversa de teste (`SimulationState::toArray()`); vazia sem teste. Só o servidor a escreve.
     *
     * @var StateShape|array{}
     */
    #[Locked]
    public array $sim = [];

    /**
     * Os erros do rascunho que impediram o teste (a lista do "Validar").
     *
     * @var list<string>
     */
    #[Locked]
    public array $simErrors = [];

    public string $simText = '';

    /**
     * Começa a conversa de teste sobre o grafo do canvas (salvo ou não): o mesmo motor do bot, sem gravar nem enviar nada. Com
     * erros no grafo mostra a lista do "Validar" em vez de simular.
     *
     * @param  array<string, mixed>  $graph
     */
    public function simStart(array $graph, string $clock, BotSimulator $simulator, WorkflowPublisher $publisher): void
    {
        $this->authorize('supervisor');
        $this->simReset();

        if (! $this->accept($graph)) {
            return;
        }

        $this->simErrors = $publisher->checkGraph($graph);

        if ($this->simErrors === []) {
            $this->sim = $simulator->start($graph, $this->user()->name, in_array($clock, self::CLOCKS, true) ? $clock : 'real')->toArray();
        }
    }

    /** @param  array<string, mixed>  $graph */
    public function simReply(array $graph, string $text, BotSimulator $simulator): void
    {
        $this->authorize('supervisor');
        $this->resetErrorBag('simText');

        $text = trim($text);

        if ($text === '' || mb_strlen($text) > self::SIM_TEXT_MAX) {
            $this->addError('simText', 'Escreva uma resposta de até '.self::SIM_TEXT_MAX.' caracteres.');

            return;
        }

        $this->simContinue($graph, fn (SimulationState $state): SimulationState => $simulator->reply($graph, $state, $text));
        $this->reset('simText');
    }

    /**
     * O cliente ficou em silêncio até o prazo do bloco que espera.
     *
     * @param  array<string, mixed>  $graph
     */
    public function simSilence(array $graph, BotSimulator $simulator): void
    {
        $this->authorize('supervisor');

        $this->simContinue($graph, fn (SimulationState $state): SimulationState => $simulator->silence($graph, $state));
    }

    public function simReset(): void
    {
        $this->authorize('supervisor');

        $this->reset('sim', 'simErrors', 'simText');
        $this->resetErrorBag('simText');
    }

    /**
     * @param  array<string, mixed>  $graph
     * @param  Closure(SimulationState): SimulationState  $step
     */
    private function simContinue(array $graph, Closure $step): void
    {
        if ($this->sim === [] || ! $this->accept($graph)) {
            return;
        }

        $this->sim = $step(SimulationState::fromArray($this->sim))->toArray();
    }

    /** @param  array<string, mixed>  $graph */
    public function saveDraft(array $graph, WorkflowPublisher $publisher): void
    {
        $this->authorize('supervisor');

        if (! $this->accept($graph)) {
            return;
        }

        $publisher->saveDraft($this->workflow, $graph, $this->user());
        $this->result = ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $graph
     * @return array<string, mixed>
     */
    public function validateDraft(array $graph, WorkflowPublisher $publisher): array
    {
        $this->authorize('supervisor');

        if (! $this->accept($graph)) {
            return $this->result;
        }

        $publisher->saveDraft($this->workflow, $graph, $this->user());

        return $this->result = ['ok' => true, 'errors' => $publisher->check($this->workflow->refresh())];
    }

    /**
     * @param  array<string, mixed>  $graph
     * @return array<string, mixed>
     */
    public function publish(array $graph, WorkflowPublisher $publisher): array
    {
        $this->authorize('supervisor');

        if (! $this->accept($graph)) {
            return $this->result;
        }

        $publisher->saveDraft($this->workflow, $graph, $this->user());

        try {
            $version = $publisher->publish($this->workflow->refresh(), $this->user());
        } catch (InvalidFlowException $e) {
            return $this->result = ['ok' => false, 'errors' => $e->errors];
        }

        return $this->result = ['ok' => true, 'version' => $version->number, 'versions' => $this->config->versions($this->workflow)];
    }

    /** @return array<string, mixed> */
    public function restore(int $versionId, WorkflowPublisher $publisher): array
    {
        $this->authorize('supervisor');

        $version = $this->config->version($this->workflow, $versionId);
        $publisher->restore($this->workflow, $version, $this->user());

        return $this->result = ['ok' => true, 'graph' => $this->workflow->refresh()->draft];
    }

    /** @param  array<string, mixed>  $graph */
    private function accept(array $graph): bool
    {
        $error = $this->shape->error($graph);

        if ($error === null) {
            return true;
        }

        $this->addError('graph', $error);
        $this->result = ['ok' => false, 'errors' => [$error]];

        return false;
    }

    private function user(): User
    {
        $user = auth()->user();
        assert($user instanceof User);

        return $user;
    }

    public function render(): View
    {
        return view('livewire.automation.editor', ['config' => $this->config->for($this->workflow)]);
    }
}
