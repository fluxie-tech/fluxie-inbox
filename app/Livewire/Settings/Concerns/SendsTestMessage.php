<?php

namespace App\Livewire\Settings\Concerns;

use App\Services\Waha\ChannelSession;
use Throwable;

/**
 * O texto de teste do cartão do canal: o telefone e a mensagem que o dono digita, e o envio pelo WAHA.
 * Usa `channel`, `fail()` e `onlyDigits()` do componente.
 */
trait SendsTestMessage
{
    public string $testPhone = '';

    public string $testText = 'Teste do painel de atendimento';

    public ?string $sentMessage = null;

    public function sendTest(): void
    {
        $this->reset('errorMessage', 'sentMessage');

        $this->validate([
            'testPhone' => ['required', 'string'],
            'testText' => ['required', 'string', 'max:4096'],
        ]);

        $phone = $this->onlyDigits($this->testPhone);

        if (! preg_match('/^\d{12,13}$/', $phone)) {
            $this->addError('testPhone', 'Informe o telefone com DDI e DDD, só números.');

            return;
        }

        try {
            $id = (new ChannelSession($this->channel))->sendTestMessage($phone, $this->testText);
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $this->sentMessage = 'Enviado: '.($id ?? 'sem id');
    }
}
