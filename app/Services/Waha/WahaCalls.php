<?php

namespace App\Services\Waha;

use App\Enums\ChannelStatus;
use App\Models\Channel;
use App\Settings\CallSettings;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Aplica a recusa de ligações em cada sessão pelo app `calls` do WAHA. Gravar (POST/PUT) reinicia a sessão por alguns
 * segundos, então só se grava quando o app existente difere do desejado; e o WAHA cria app até para sessão que não existe,
 * por isso o lote só visita canais ativos e conectados.
 */
class WahaCalls
{
    /** @return bool `true` quando escreveu no WAHA, `false` quando já estava como deveria */
    public function apply(Channel $channel): bool
    {
        $client = new WahaClient($channel);
        $session = $client->sessionName();
        $id = 'app_'.$session;

        $response = $client->http()->get('/api/apps', ['session' => $session]);
        $client->check($response);

        $existing = null;

        foreach ((array) $response->json() as $app) {
            if (is_array($app) && ($app['id'] ?? null) === $id) {
                $existing = $app;
            }
        }

        if (! CallSettings::rejects()) {
            if ($existing === null) {
                return false;
            }

            $client->check($client->http()->delete('/api/apps/'.$id));

            return true;
        }

        $config = [
            'dm' => ['reject' => true, 'message' => CallSettings::message()],
            'group' => ['reject' => true],
        ];

        if ($existing === null) {
            $client->check($client->http()->post('/api/apps', ['app' => 'calls', 'id' => $id, 'session' => $session, 'config' => $config]));

            return true;
        }

        if (($existing['enabled'] ?? false) === true && ($existing['config'] ?? null) == $config) {
            return false;
        }

        $client->check($client->http()->put('/api/apps/'.$id, ['app' => 'calls', 'id' => $id, 'session' => $session, 'config' => $config, 'enabled' => true]));

        return true;
    }

    public function applyQuietly(Channel $channel): void
    {
        try {
            $this->apply($channel);
        } catch (Throwable $e) {
            Log::warning('Não foi possível aplicar a recusa de ligações no WAHA.', ['channel' => $channel->id, 'error' => $e->getMessage()]);
        }
    }

    /** @return array{applied: int, unchanged: int, failed: int} */
    public function applyAll(): array
    {
        $result = ['applied' => 0, 'unchanged' => 0, 'failed' => 0];

        $channels = Channel::query()->where('active', true)->where('status', ChannelStatus::WORKING)->get();

        foreach ($channels as $channel) {
            try {
                $result[$this->apply($channel) ? 'applied' : 'unchanged']++;
            } catch (Throwable $e) {
                $result['failed']++;
                Log::warning('Não foi possível aplicar a recusa de ligações no WAHA.', ['channel' => $channel->id, 'error' => $e->getMessage()]);
            }
        }

        return $result;
    }
}
