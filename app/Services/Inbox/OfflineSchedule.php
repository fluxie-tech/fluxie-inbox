<?php

namespace App\Services\Inbox;

use App\Jobs\GoOfflineJob;
use App\Models\Channel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * O `offline` pendente do canal: um token na chave e um job com atraso. Agendar de novo troca o token, então o job
 * anterior perde a vez (`isCurrent` falha para ele).
 */
class OfflineSchedule
{
    /** Quanto tempo sem envios antes de voltar a "offline". */
    public const OFFLINE_AFTER_SECONDS = 45;

    public function schedule(Channel $channel, ?int $delaySeconds = null): void
    {
        $token = Str::random(16);

        Cache::put($this->key($channel->id), $token, now()->addMinutes(5));
        GoOfflineJob::dispatch($channel->id, $token)->delay(now()->addSeconds($delaySeconds ?? self::OFFLINE_AFTER_SECONDS));
    }

    /** O token gravado é este: nenhum agendamento mais novo tomou o lugar do job. */
    public function isCurrent(Channel $channel, string $token): bool
    {
        return Cache::get($this->key($channel->id)) === $token;
    }

    private function key(int $channelId): string
    {
        return "presence-offline:{$channelId}";
    }
}
