<?php

namespace App\Services\Alerts;

use App\Enums\ChannelStatus;
use App\Events\ChannelStatusChanged;
use App\Jobs\ConfirmChannelDownJob;
use App\Models\Channel;
use App\Notifications\ChannelDown;
use App\Notifications\ChannelNumberChanged;
use App\Notifications\ChannelUp;
use Illuminate\Support\Facades\Notification;

/** O único lugar que transforma um fato do canal (caiu, voltou, mudou de número) em alertas, usando {@see AlertRecipients}. */
final class ChannelAlerts
{
    public function __construct(private readonly AlertRecipients $recipients) {}

    /** Quanto o canal pode ficar fora de WORKING sem ser queda: o WhatsApp oscila por ~1 s várias vezes ao dia. */
    public const GRACE_SECONDS = 60;

    /**
     * Estados que pedem uma pessoa (QR novo ou sessão perdida): não são reconexão automática, então avisam na hora.
     * Os demais esperam {@see self::GRACE_SECONDS}. A faixa se atualiza em qualquer mudança de estado.
     */
    public function channelChanged(Channel $channel, ChannelStatus $from): void
    {
        ChannelStatusChanged::dispatch($channel->id, $channel->status->value);

        if (! $channel->active) {
            return;
        }

        if ($channel->isConnected()) {
            $this->announceUp($channel);
        } elseif (in_array($channel->status, [ChannelStatus::SCAN_QR_CODE, ChannelStatus::FAILED], true)) {
            $this->confirmDown($channel);
        } elseif ($from === ChannelStatus::WORKING) {
            ConfirmChannelDownJob::dispatch($channel->id)->delay(self::GRACE_SECONDS);
        }
    }

    /**
     * Anuncia a queda uma única vez por episódio (faixa, som e sino). Só vale para quem já esteve conectado nesta
     * queda (`down_since`): o primeiro pareamento passa por "Aguardando QR" sem ser queda.
     */
    public function confirmDown(Channel $channel): void
    {
        $claimed = Channel::query()->whereKey($channel->id)
            ->where('active', true)
            ->where('status', '<>', ChannelStatus::WORKING->value)
            ->whereNotNull('down_since')
            ->whereNull('down_alerted_at')
            ->update(['down_alerted_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        Notification::send($this->recipients->everyone(), new ChannelDown($channel));
        ChannelStatusChanged::dispatch($channel->id, $channel->status->value);
    }

    /** "Voltou" só existe se o "caiu" foi anunciado: uma oscilação que ninguém viu não ganha aviso de volta. */
    private function announceUp(Channel $channel): void
    {
        $announced = Channel::query()->whereKey($channel->id)->whereNotNull('down_alerted_at')->update(['down_alerted_at' => null]);

        if ($announced === 1) {
            Notification::send($this->recipients->everyone(), new ChannelUp($channel));
        }
    }

    /** O WAHA informou outro número para a sessão: aviso informativo no sino, sem som e sem bloquear nada. */
    public function numberChanged(Channel $channel): void
    {
        Notification::send($this->recipients->everyone(), new ChannelNumberChanged($channel));
    }
}
