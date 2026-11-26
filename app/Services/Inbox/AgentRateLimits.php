<?php

namespace App\Services\Inbox;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/** Os freios por atendente da barra de envio: mensagens enviadas e avisos de presença. */
final class AgentRateLimits
{
    /** "Gravando áudio…" chega a cada poucos segundos; limite próprio, mais folgado que o de envio. */
    private const PRESENCE_PER_MINUTE = 20;

    /** Freio contra conta comprometida ou laço no navegador: reserva `$count` envios, ou nenhum se não cabem. */
    public function reserveSends(User $user, int $count): bool
    {
        $key = 'send-message:'.$user->id;
        $max = config()->integer('security.send_message_per_minute');

        if (RateLimiter::remaining($key, $max) < $count) {
            return false;
        }

        for ($i = 0; $i < $count; $i++) {
            RateLimiter::hit($key, 60);
        }

        return true;
    }

    public function allowPresence(User $user): bool
    {
        return RateLimiter::attempt('presence:'.$user->id, self::PRESENCE_PER_MINUTE, fn (): bool => true, 60) === true;
    }
}
