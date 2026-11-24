<?php

namespace App\Settings;

use App\Models\Setting;

/** Recusa de ligações em `settings`: a chave, o texto enviado ao cliente e os textos que já foram usados. */
final class CallSettings
{
    public const DEFAULT_MESSAGE = 'Este número não recebe ligações. Envie sua mensagem em texto ou áudio que responderemos por aqui.';

    public const MAX_MESSAGE = 500;

    private const MAX_PREVIOUS = 10;

    public static function rejects(): bool
    {
        return Setting::get('calls.reject', true) !== false;
    }

    public static function message(): string
    {
        $message = Setting::get('calls.message');

        return is_string($message) && $message !== '' ? $message : self::DEFAULT_MESSAGE;
    }

    /** Guarda o texto que sai quando ele muda; a recuperação reconhece com isso as respostas antigas que relê do WAHA. */
    public static function save(bool $reject, string $message): void
    {
        $current = self::message();

        if ($message !== '' && $current !== $message) {
            $previous = array_values(array_unique([$current, ...self::previous()]));
            Setting::put('calls.previous_messages', array_slice($previous, 0, self::MAX_PREVIOUS));
        }

        Setting::put('calls.reject', $reject, 'boolean');
        Setting::put('calls.message', $message);
    }

    /** @return list<string> o texto atual e os anteriores */
    public static function knownMessages(): array
    {
        return array_values(array_unique([self::message(), ...self::previous()]));
    }

    /** O texto é o da resposta automática (atual ou antigo) e a recusa está ligada. */
    public static function isReplyText(string $body): bool
    {
        return self::rejects() && in_array(trim($body), array_map('trim', self::knownMessages()), true);
    }

    /** @return list<string> */
    private static function previous(): array
    {
        $stored = Setting::get('calls.previous_messages', []);

        return is_array($stored) ? array_values(array_filter($stored, is_string(...))) : [];
    }
}
