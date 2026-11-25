<?php

namespace App\Services\Reset;

use App\Models\Channel;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaRequestException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;

/** As sessões do WAHA no zerar: quais são, se o WAHA responde e a desconexão delas. */
class WahaSessions
{
    /** As sessões do WAHA dos canais, lidas antes de o banco ser zerado (`list<string>`). */
    public const SESSIONS = 'reset:waha-sessions';

    /** @return list<string> */
    public function current(): array
    {
        return array_values(Channel::query()->whereNotNull('waha_session')->get()->map(fn (Channel $channel): string => $channel->waha_session)->all());
    }

    public function problem(): ?string
    {
        foreach ($this->current() as $name) {
            try {
                $this->waha($name)->session();
            } catch (ConnectionException) {
                return 'O WAHA não respondeu. Confira se ele está no ar antes de zerar.';
            } catch (WahaRequestException) {
                // O WAHA respondeu (404 de sessão que já não existe, por exemplo): está no ar.
            }
        }

        return null;
    }

    /**
     * As sessões guardadas no começo mais as dos canais de agora (um canal criado depois também sai). O banco pode estar sem a
     * tabela, num `migrate:fresh` interrompido: aí valem só as guardadas.
     *
     * @return list<string>
     */
    public function toDisconnect(): array
    {
        try {
            $current = $this->current();
        } catch (QueryException) {
            $current = [];
        }

        return array_values(array_unique([...$this->stored(), ...$current]));
    }

    /** @param  list<string>  $sessions */
    public function disconnect(array $sessions): void
    {
        foreach ($sessions as $name) {
            $client = $this->waha($name);

            try {
                $client->logout();
            } catch (WahaRequestException $e) {
                // Sessão parada ou desconectada pode recusar o logout; repetir o mesmo erro travaria o `--continue`.
                report($e);
            }

            $client->deleteSession();
        }
    }

    /** @return list<string> */
    private function stored(): array
    {
        $sessions = Cache::get(self::SESSIONS, []);

        return is_array($sessions) ? array_values(array_filter($sessions, is_string(...))) : [];
    }

    /** O cliente do WAHA exige um canal: um em memória, só com a sessão, basta. */
    private function waha(string $session): WahaClient
    {
        return new WahaClient((new Channel)->forceFill(['waha_session' => $session]));
    }
}
