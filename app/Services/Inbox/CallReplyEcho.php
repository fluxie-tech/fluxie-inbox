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
     * shortcut: casa pela origem API mais o texto; a guarda roda antes do casamento do eco das mensagens do painel, então vale também dentro da janela do eco; o único efeito visível é um envio do painel que estourou o prazo (FAILED "sem resposta") com exatamente o texto da recusa não voltar a "enviada" pelo eco. Trocar por marca do WAHA se ele passar a identificar a resposta do app calls.
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
