<?php

namespace App\Services\Waha;

use App\Models\Channel;
use Throwable;

/**
 * A sessão do canal no WAHA, para a tela de Canais: iniciar, parar, apagar, parear e mandar o texto de teste.
 * As exceções do WAHA sobem; quem chama decide como mostrá-las.
 */
final class ChannelSession
{
    private readonly WahaClient $client;

    public function __construct(private readonly Channel $channel)
    {
        $this->client = new WahaClient($channel);
    }

    public function start(): void
    {
        $this->client->start();
    }

    public function stop(): void
    {
        $this->client->stop();
    }

    public function deleteSession(): void
    {
        $this->client->deleteSession();
    }

    /** @return array<string, mixed> */
    public function session(): array
    {
        return $this->client->session();
    }

    public function requestPairingCode(string $phone): string
    {
        return $this->client->requestPairingCode($phone);
    }

    /** Devolve o id da mensagem enviada (ou `null` se o WAHA não trouxe). */
    public function sendTestMessage(string $phone, string $text): ?string
    {
        $response = $this->client->sendText($this->channel->normalizeChatId($phone), $text);

        try {
            $this->client->setPresence('offline');
        } catch (Throwable) {
            // Presença online segura o push no celular do atendente; é best-effort, a mensagem já foi enviada.
        }

        $id = $response['id'] ?? null;

        return is_string($id) ? $id : null;
    }
}
