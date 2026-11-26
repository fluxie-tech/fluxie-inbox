<?php

namespace App\Services\Bot\Effects;

use App\Models\Ticket;
use App\Services\Inbox\Presence;
use App\Services\Waha\WahaClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/** A pausa do bot com "digitando" no WhatsApp do cliente. */
final class LiveTyping
{
    public function __construct(private readonly Ticket $ticket) {}

    /** A presença é melhor esforço: se o WAHA falhar, só a pausa fica. */
    public function wait(int $seconds): void
    {
        $contact = $this->ticket->contact()->firstOrFail();
        $chatId = $contact->wa_id ?? (string) $contact->lid;
        $client = new WahaClient($this->ticket->channel()->firstOrFail());

        try {
            $client->startTyping($chatId);
        } catch (Throwable $e) {
            Log::warning('Bot: "digitando" falhou e foi ignorado', ['erro' => $e->getMessage()]);
        }

        Sleep::for($seconds)->seconds();

        try {
            $client->stopTyping($chatId);
        } catch (Throwable $e) {
            Log::warning('Bot: parar "digitando" falhou e foi ignorado', ['erro' => $e->getMessage()]);
        }

        app(Presence::class)->botTyped($this->ticket);
    }
}
