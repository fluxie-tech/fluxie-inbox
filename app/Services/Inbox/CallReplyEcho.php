<?php

namespace App\Services\Inbox;

use App\Models\WahaEvent;
use App\Settings\CallSettings;

/**
 * O eco da resposta automática de ligação recusada (app `calls` do WAHA): não é conversa nem trabalho humano.
 */
final class CallReplyEcho
{
    /**
     * shortcut: casa pela origem API mais o texto; a mesma frase enviada pelo painel e com eco fora da janela também seria ignorada. Trocar por marca do WAHA se ele passar a identificar a resposta do app calls.
     */
    public static function matches(WahaEvent $event): bool
    {
        $data = $event->payload['payload'] ?? null;

        return is_array($data)
            && ($data['fromMe'] ?? false) === true
            && ($data['source'] ?? null) === 'api'
            && ! ($data['hasMedia'] ?? false)
            && is_string($data['body'] ?? null)
            && CallSettings::isReplyText($data['body']);
    }
}
