<?php

namespace App\Services\Inbox;

use App\Models\Channel;
use App\Models\Contact;
use Illuminate\Support\Facades\RateLimiter;

/** O ritmo das conversas iniciadas pelo app: um intervalo mínimo por canal e um teto por contato em uma hora. */
class ConversationPace
{
    /** Segundos mínimos entre duas conversas iniciadas pelo app no mesmo canal, e o teto por contato em uma hora. */
    private const CHANNEL_GAP_SECONDS = 30;

    private const CONTACT_PER_HOUR = 4;

    /** @throws ConversationNotStarted */
    public function reserve(Contact $contact, Channel $channel): void
    {
        $channelKey = $this->channelKey($channel);
        $contactKey = $this->contactKey($contact);

        foreach ([[$channelKey, 1], [$contactKey, self::CONTACT_PER_HOUR]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new ConversationNotStarted('Aguarde '.RateLimiter::availableIn($key).' s para iniciar outra conversa '.($key === $channelKey ? 'neste canal.' : 'com este contato.'));
            }
        }

        RateLimiter::hit($channelKey, self::CHANNEL_GAP_SECONDS);
        RateLimiter::hit($contactKey, 3600);
    }

    public function release(Contact $contact, Channel $channel): void
    {
        // Só o que esta chamada reservou: `clear` apagaria também a reserva de outra conversa feita no mesmo canal na janela.
        RateLimiter::decrement($this->channelKey($channel), self::CHANNEL_GAP_SECONDS);
        RateLimiter::decrement($this->contactKey($contact), 3600);
    }

    private function channelKey(Channel $channel): string
    {
        return 'start-conversation:channel:'.$channel->id;
    }

    private function contactKey(Contact $contact): string
    {
        return 'start-conversation:contact:'.$contact->id;
    }
}
