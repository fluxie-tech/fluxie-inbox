<?php

namespace App\Services\Bot;

use App\Services\Bot\Flow\NodeType;

/**
 * A forma do grafo que chega do navegador: tamanho, tipos conhecidos e ids simples. O conteúdo dos blocos é conferido
 * ao publicar e, na execução, o motor ignora o que não reconhece.
 */
final class FlowGraphShape
{
    public const MAX_NODES = 300;

    public const MAX_EDGES = 600;

    public const MAX_BYTES = 524288;

    /**
     * O erro da forma do grafo, ou null quando ele cabe.
     *
     * @param  array<string, mixed>  $graph
     */
    public function error(array $graph): ?string
    {
        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        $edges = is_array($graph['edges'] ?? null) ? $graph['edges'] : [];

        return match (true) {
            count($nodes) > self::MAX_NODES || count($edges) > self::MAX_EDGES => 'O workflow passou do tamanho máximo.',
            strlen((string) json_encode($graph)) > self::MAX_BYTES => 'O workflow passou do tamanho máximo.',
            array_filter($nodes, fn (mixed $n): bool => ! $this->knownNode($n)) !== [] => 'O workflow tem um bloco de tipo desconhecido.',
            ! $this->validSettings($graph['settings'] ?? null) => 'As mensagens da Automação são inválidas.',
            default => null,
        };
    }

    /** `settings` ausente vale; presente, só as mensagens conhecidas, em texto e dentro do limite. */
    private function validSettings(mixed $settings): bool
    {
        if ($settings === null) {
            return true;
        }

        $messages = is_array($settings) ? ($settings['mensagens'] ?? []) : null;

        return is_array($messages) && array_filter(
            $messages,
            fn (mixed $text, mixed $key): bool => ! in_array($key, BotMessages::KEYS, true) || ! is_string($text) || mb_strlen($text) > BotMessages::MAX_LENGTH,
            ARRAY_FILTER_USE_BOTH,
        ) === [];
    }

    /** Bloco com id simples e tipo que o motor conhece. */
    private function knownNode(mixed $node): bool
    {
        return is_array($node)
            && is_string($node['id'] ?? null) && strlen($node['id']) <= 40
            && is_string($node['type'] ?? null) && NodeType::tryFrom($node['type']) !== null;
    }
}
