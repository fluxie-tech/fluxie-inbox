<?php

namespace App\Services\Waha;

use App\Enums\ChannelStatus;
use App\Models\Channel;

/**
 * As operações da sessão do canal no WAHA que mudam o estado dele: iniciar, parar, desativar, apagar a sessão, consultá-la e
 * pedir o QR ou o código de pareamento. Cada uma age na mesma instância de `Channel` que recebe. As exceções do WAHA sobem;
 * a tela decide como mostrá-las.
 */
final class ChannelLink
{
    public function __construct(private readonly WahaCalls $calls, private readonly ChannelPhone $phone) {}

    public function start(Channel $channel): void
    {
        (new ChannelSession($channel))->start();

        $this->calls->applyQuietly($channel);
        $this->setStatus($channel, ChannelStatus::STARTING);
    }

    public function stop(Channel $channel): void
    {
        (new ChannelSession($channel))->stop();

        $this->setStatus($channel, ChannelStatus::STOPPED);
    }

    public function deactivate(Channel $channel): void
    {
        (new ChannelSession($channel))->deleteSession();

        $channel->active = false;
        $this->setStatus($channel, ChannelStatus::STOPPED);
    }

    public function deleteSession(Channel $channel): void
    {
        (new ChannelSession($channel))->deleteSession();

        $this->setStatus($channel, ChannelStatus::STOPPED);
    }

    /**
     * Pergunta o estado da sessão ao WAHA e o grava no canal (com o telefone). Sessão que não existe (404) não é erro: o canal
     * fica parado e `missing` vem verdadeiro.
     *
     * @return array{missing: bool, status: ?ChannelStatus}
     */
    public function refresh(Channel $channel): array
    {
        try {
            $session = (new ChannelSession($channel))->session();
        } catch (WahaRequestException $exception) {
            if ($exception->statusCode !== 404) {
                throw $exception;
            }

            $this->setStatus($channel, ChannelStatus::STOPPED);

            return ['missing' => true, 'status' => null];
        }

        $raw = $session['status'] ?? null;
        $status = is_string($raw) ? ChannelStatus::tryFrom($raw) : null;

        if ($status !== null) {
            $this->setStatus($channel, $status);
        }

        $me = data_get($session, 'me.id');
        $this->phone->sync($channel, is_string($me) ? $me : null);

        return ['missing' => false, 'status' => $status];
    }

    /**
     * O QR da sessão: o texto cru (o navegador desenha com a logo) ou, quando ele não vem ou `$preferPng`, a imagem do WAHA.
     *
     * @return array{value: ?string, png: ?string}
     */
    public function qr(Channel $channel, bool $preferPng): array
    {
        $waha = WahaQr::forChannel($channel);
        $value = $preferPng ? null : $waha->raw();

        return ['value' => $value, 'png' => $value === null ? $waha->dataUri() : null];
    }

    public function requestPairingCode(Channel $channel, string $phone): string
    {
        return (new ChannelSession($channel))->requestPairingCode($phone);
    }

    private function setStatus(Channel $channel, ChannelStatus $status): void
    {
        $channel->status = $status;
        $channel->last_event_at = now();
        $channel->save();
    }
}
