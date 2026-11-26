<?php

namespace App\Settings;

use App\Models\Setting;
use App\Models\Ticket;

/** Encerramento automático em `settings` (`auto_close.*`): quando avisar e encerrar chamado parado, e os textos enviados. */
final class AutoCloseSettings
{
    public const DEFAULT_WARN = 'Olá, {{nome}}! Ainda está por aí? Se não precisar de mais nada, vamos encerrar o atendimento {{protocolo}} em breve.';

    public const DEFAULT_FAREWELL = 'Encerramos o atendimento {{protocolo}} por falta de resposta. Se precisar, é só mandar uma mensagem por aqui.';

    public const MIN_MINUTES = 15;

    public const MAX_IDLE_MINUTES = 43200;

    public const MAX_AFTER_WARN_MINUTES = 10080;

    public const MAX_MESSAGE = 500;

    /** Chaves aceitas em `save()`, sem o prefixo `auto_close.`. */
    private const KEYS = ['enabled', 'idle_minutes', 'warn_enabled', 'warn_message', 'close_after_warn_minutes', 'farewell_enabled', 'farewell_message', 'business_hours_only'];

    public static function enabled(): bool
    {
        return self::flag('enabled', true);
    }

    /** Todos os ajustes lidos de uma vez, para a rotina que decide centenas de chamados numa rodada. */
    public static function snapshot(): AutoCloseSnapshot
    {
        return new AutoCloseSnapshot(
            enabled: self::enabled(),
            idleMinutes: self::idleMinutes(),
            warnEnabled: self::warnEnabled(),
            warnMessage: self::warnMessage(),
            closeAfterWarnMinutes: self::closeAfterWarnMinutes(),
            farewellEnabled: self::farewellEnabled(),
            farewellMessage: self::farewellMessage(),
            businessHoursOnly: self::businessHoursOnly(),
        );
    }

    /** Minutos sem resposta do cliente até avisar (ou encerrar, sem aviso). */
    public static function idleMinutes(): int
    {
        return self::minutes('idle_minutes', 1440, self::MAX_IDLE_MINUTES);
    }

    public static function warnEnabled(): bool
    {
        return self::flag('warn_enabled', false);
    }

    public static function warnMessage(): string
    {
        return self::text('warn_message', self::DEFAULT_WARN);
    }

    /** Minutos entre o aviso e o encerramento. */
    public static function closeAfterWarnMinutes(): int
    {
        return self::minutes('close_after_warn_minutes', 60, self::MAX_AFTER_WARN_MINUTES);
    }

    public static function farewellEnabled(): bool
    {
        return self::flag('farewell_enabled', false);
    }

    public static function farewellMessage(): string
    {
        return self::text('farewell_message', self::DEFAULT_FAREWELL);
    }

    /** Os minutos contam só dentro do horário de atendimento. */
    public static function businessHoursOnly(): bool
    {
        return self::flag('business_hours_only', false);
    }

    /**
     * Grava só as chaves presentes em `$values`. Não valida: valor inválido volta ao padrão na leitura.
     *
     * @param  array<string, mixed>  $values
     */
    public static function save(array $values): void
    {
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $values)) {
                Setting::put('auto_close.'.$key, $values[$key]);
            }
        }
    }

    /** Troca `{{nome}}` e `{{protocolo}}`; texto vazio vale o `$default` (o aviso padrão, se não vier outro). */
    public static function render(string $template, Ticket $ticket, string $default = self::DEFAULT_WARN): string
    {
        $template = trim($template) === '' ? $default : $template;

        return strtr($template, ['{{nome}}' => $ticket->contact?->label() ?? 'cliente', '{{protocolo}}' => (string) $ticket->protocol]);
    }

    private static function flag(string $key, bool $default): bool
    {
        $value = Setting::get('auto_close.'.$key, $default);

        return is_bool($value) ? $value : $default;
    }

    private static function minutes(string $key, int $default, int $max): int
    {
        $value = Setting::get('auto_close.'.$key, $default);

        return is_int($value) && $value >= self::MIN_MINUTES && $value <= $max ? $value : $default;
    }

    private static function text(string $key, string $default): string
    {
        $value = Setting::get('auto_close.'.$key);

        return is_string($value) && $value !== '' && mb_strlen($value) <= self::MAX_MESSAGE ? $value : $default;
    }
}
