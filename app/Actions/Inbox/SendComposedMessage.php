<?php

namespace App\Actions\Inbox;

use App\Jobs\SendTicketMessageJob;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\AgentRateLimits;
use App\Services\Inbox\AttachmentRules;
use App\Services\Inbox\OutgoingMessages;
use App\Services\Inbox\SendTicketMedia;
use App\Services\Inbox\Signature;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Envia o que o atendente compôs: o texto e/ou os arquivos da bandeja. Cada arquivo é uma mensagem; o texto vira a legenda
 * do primeiro, exceto quando o primeiro é áudio (voz não tem legenda) ou a legenda passa de 1024 caracteres: nesses casos
 * sai antes, como mensagem própria. A ordem final é a da fila de saída.
 */
final class SendComposedMessage
{
    /** Tamanho máximo de uma mensagem de texto do WhatsApp na tela. */
    public const MESSAGE_LIMIT = 4096;

    /** Legenda de mídia no WhatsApp: acima disto o texto sai como mensagem separada. */
    private const CAPTION_LIMIT = 1024;

    public function __construct(
        private readonly AgentRateLimits $limits,
        private readonly OutgoingMessages $outgoing,
        private readonly SendTicketMedia $media,
    ) {}

    /**
     * Só a primeira mensagem do envio cita: o texto separado ou, sem ele, o primeiro arquivo.
     *
     * @param  list<UploadedFile>  $files
     * @return array{error: ?string, failures: list<string>} `error` = nada foi enviado (a bandeja fica); `failures` = arquivos recusados
     */
    public function __invoke(Ticket $ticket, User $by, string $draft, array $files, ?Message $quoted): array
    {
        $captioned = $this->captionsFirstFile($draft, $files);
        $separateText = $draft !== '' && ! $captioned;

        $text = $separateText ? Signature::apply($draft, $by, $ticket->sector) : '';

        // O limite vale para o texto que sai, já com a assinatura.
        if (mb_strlen($text) > self::MESSAGE_LIMIT) {
            return ['error' => 'Com a assinatura, a mensagem passa de '.self::MESSAGE_LIMIT.' caracteres.', 'failures' => []];
        }

        // Freio contra conta comprometida ou laço no navegador: cada mensagem enviada conta, arquivo inclusive.
        if (! $this->limits->reserveSends($by, count($files) + ($separateText ? 1 : 0))) {
            return ['error' => 'Você está enviando mensagens rápido demais. Aguarde alguns segundos.', 'failures' => []];
        }

        if ($separateText) {
            SendTicketMessageJob::dispatch($this->outgoing->create($ticket, $by, $text, quoted: $quoted)->id);
            $quoted = null;
        }

        return ['error' => null, 'failures' => $this->sendFiles($ticket, $by, $files, $captioned ? $draft : '', $quoted)];
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function captionsFirstFile(string $draft, array $files): bool
    {
        if ($files === [] || $draft === '' || mb_strlen($draft) > self::CAPTION_LIMIT) {
            return false;
        }

        $info = AttachmentRules::inspect($files[0]);

        return is_array($info) && $info['kind'] !== 'audio';
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return list<string> mensagens de erro dos arquivos que o servidor recusou na hora de enviar
     */
    private function sendFiles(Ticket $ticket, User $by, array $files, string $caption, ?Message $quoted): array
    {
        $failures = [];

        foreach ($files as $position => $file) {
            try {
                ($this->media)($ticket, $by, $file, $position === 0 ? $caption : '', false, $position === 0 ? $quoted : null);
            } catch (InvalidArgumentException $e) {
                $failures[] = $e->getMessage();
            }
        }

        return $failures;
    }
}
