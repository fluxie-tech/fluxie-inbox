<?php

namespace App\Services\Bot;

use App\Models\Sector;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;

/** O que o editor visual recebe do servidor além do grafo: setores, etiquetas, usuários, versões, mensagens e variáveis. */
final class EditorConfig
{
    /** @return array<string, mixed> */
    public function for(Workflow $workflow): array
    {
        return [
            'graph' => $workflow->draft,
            'sectors' => Sector::query()->orderBy('menu_order')->get(['id', 'name'])->map(fn (Sector $s): array => ['id' => $s->id, 'name' => $s->name])->all(),
            'tags' => Tag::sortedByName(Tag::query()->get(['id', 'name', 'scope']))->map(fn (Tag $t): array => ['id' => $t->id, 'name' => $t->name, 'scope' => $t->scope->value, 'label' => $t->name.' ('.mb_strtolower($t->scope->label()).')'])->all(),
            'users' => User::query()->where('active', true)->orderBy('name')->get(['id', 'name'])->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->all(),
            'versions' => $this->versions($workflow),
            'messages' => $this->messageCatalog(),
            'variables' => array_map(fn (string $key, string $label): array => ['key' => $key, 'label' => $label], array_keys(Vars::BUILTIN), Vars::BUILTIN),
        ];
    }

    /** @return list<array{id: int, number: int, at: string, by: string}> */
    public function versions(Workflow $workflow): array
    {
        return array_values($workflow->versions()->with('publisher')->orderByDesc('number')->get()
            ->map(fn (WorkflowVersion $v): array => [
                'id' => $v->id,
                'number' => $v->number,
                'at' => $v->published_at->timezone(config()->string('app.display_timezone'))->format('d/m/Y H:i'),
                'by' => $v->publisher->name ?? 'usuário removido',
            ])->all());
    }

    /** A versão do workflow (e só dele) para restaurar. */
    public function version(Workflow $workflow, int $id): WorkflowVersion
    {
        return WorkflowVersion::query()->where('workflow_id', $workflow->id)->findOrFail($id);
    }

    /** @return list<array{key: string, label: string, default: string}> */
    private function messageCatalog(): array
    {
        $labels = [
            'encaminhamento_setor' => 'Aviso ao entregar à fila (com setor)',
            'encaminhamento_geral' => 'Aviso ao entregar à fila (sem setor)',
            'opcao_invalida' => 'Aviso de opção inválida (menu)',
            'resposta_invalida' => 'Aviso de resposta inválida (pergunta)',
            'rodape_sair' => 'Dica de como falar com um atendente (fim do menu)',
        ];

        return array_map(fn (string $key): array => ['key' => $key, 'label' => $labels[$key], 'default' => BotMessages::DEFAULTS[$key]], BotMessages::KEYS);
    }
}
