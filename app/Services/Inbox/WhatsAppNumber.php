<?php

namespace App\Services\Inbox;

use App\Models\Channel;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaContacts;
use App\Services\Waha\WahaRequestException;

class WhatsAppNumber
{
    /**
     * Público para a janela conferir um telefone novo antes de cadastrar o contato (número sem WhatsApp não vira contato).
     *
     * @throws ConversationNotStarted
     */
    public function assertExists(string $waId, Channel $channel): void
    {
        try {
            $exists = (new WahaContacts(new WahaClient($channel)))->numberExists($waId);
        } catch (WahaRequestException) {
            throw new ConversationNotStarted('Não foi possível confirmar o número agora. Tente de novo em instantes.');
        }

        if (! $exists) {
            throw new ConversationNotStarted('Este número não tem WhatsApp.');
        }
    }
}
