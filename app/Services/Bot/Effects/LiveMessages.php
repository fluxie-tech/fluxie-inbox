<?php

namespace App\Services\Bot\Effects;

use App\Enums\MessageOrigin;
use App\Jobs\SendTicketMessageJob;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\Inbox\AttachmentRules;
use App\Services\Inbox\OutgoingMessages;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** As mensagens de texto e de mídia que o bot grava e despacha no ticket. */
final class LiveMessages
{
    public function __construct(private readonly Ticket $ticket) {}

    /** Grava e despacha uma mensagem do bot; visto e "digitando" saem em `SendTicketMessage::deliver`, como no envio humano. */
    public function say(string $text): void
    {
        if (trim($text) === '') {
            return;
        }

        $message = app(OutgoingMessages::class)->create($this->ticket, null, $text, MessageOrigin::BOT);
        SendTicketMessageJob::dispatch($message->id)->afterCommit();
    }

    /** Arquivo que sumiu é registrado no log e o fluxo segue. */
    public function media(string $path, string $caption): void
    {
        $disk = Storage::disk('local');

        // O caminho vem do grafo (dado não confiável): só vale dentro do storage, sem subir de diretório.
        if ($path === '' || str_contains($path, '..') || ! $disk->exists($path)) {
            Log::warning('Bot: arquivo do bloco Enviar mídia não encontrado', ['ticket' => $this->ticket->id, 'path' => $path]);

            return;
        }

        $mime = $disk->mimeType($path);
        $copy = $this->copyToMediaDisk($disk, $path);

        if ($copy === null) {
            Log::warning('Bot: não foi possível copiar o arquivo do bloco Enviar mídia', ['ticket' => $this->ticket->id, 'path' => $path]);

            return;
        }

        $message = app(OutgoingMessages::class)->record(
            $this->ticket,
            null,
            'media',
            $caption,
            MessageOrigin::BOT,
            [
                'media_mime' => is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream',
                'media_name' => AttachmentRules::safeName(basename($path)),
                'media_size' => $disk->size($path),
                'media_path' => $copy,
            ],
        );
        SendTicketMessageJob::dispatch($message->id)->afterCommit();
    }

    /**
     * O arquivo do bloco é a fonte (disco `local`, pasta `bot/`); o anexo é uma cópia no disco de mídia, que a purga de 90 dias
     * pode apagar sem tocar na fonte.
     */
    private function copyToMediaDisk(Filesystem $source, string $path): ?string
    {
        $stream = $source->readStream($path);
        $key = 'outbound/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.'.pathinfo($path, PATHINFO_EXTENSION);

        if ($stream === null || Message::mediaDisk()->writeStream($key, $stream) === false) {
            return null;
        }

        return $key;
    }
}
