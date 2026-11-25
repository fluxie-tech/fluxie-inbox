<?php

namespace App\Services\Security;

use App\Enums\AuditAction;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JsonException;

/** Relatórios de violação da CSP enviados pelo navegador: lê os dois formatos e grava um por hora e por violação igual. */
final class CspReports
{
    private const MAX_ITEMS = 10;

    private const MAX_TEXT = 255;

    /** @return list<array{directive: string, blocked: string, document: string, source: ?string, line: ?int, disposition: string}> */
    public function parse(string $body, string $contentType): array
    {
        try {
            $json = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $items = match (strtolower(trim(explode(';', $contentType)[0]))) {
            'application/csp-report' => is_array($json) && is_array($json['csp-report'] ?? null)
                ? [self::fromClassic($json['csp-report'])] : [],
            'application/reports+json' => is_array($json) ? self::fromReporting($json) : [],
            default => [],
        };

        return array_slice(array_values(array_filter($items)), 0, self::MAX_ITEMS);
    }

    /** @param array{directive: string, blocked: string, document: string, source: ?string, line: ?int, disposition: string} $violation */
    public function record(array $violation): void
    {
        $key = 'csp-report:'.sha1($violation['directive'].'|'.$violation['blocked'].'|'.$violation['document']);

        if (! Cache::add($key, true, 3600) || ! $this->underHourlyCap()) {
            return;
        }

        Auditor::record(AuditAction::CspViolation, null, $violation['directive'], [
            'blocked' => $violation['blocked'],
            'document' => $violation['document'],
            'source' => $violation['source'],
            'line' => $violation['line'],
            'disposition' => $violation['disposition'],
        ], 'Navegador');
        Log::warning('CSP: violação', $violation);
    }

    /** Teto global de linhas por hora: um IP variando a URI bloqueada não pode encher a auditoria. Avisa uma vez por hora. */
    private function underHourlyCap(): bool
    {
        $key = 'csp-report-hour:'.now()->format('YmdH');
        Cache::add($key, 0, 3600);
        $count = (int) Cache::increment($key);
        $cap = config()->integer('security.csp_report_per_hour');

        if ($count === $cap + 1) {
            Log::warning('CSP: teto de relatórios por hora atingido', ['teto' => $cap]);
        }

        return $count <= $cap;
    }

    /**
     * @param  array<mixed>  $items
     * @return list<array{directive: string, blocked: string, document: string, source: ?string, line: ?int, disposition: string}|null>
     */
    private static function fromReporting(array $items): array
    {
        $found = [];

        foreach ($items as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'csp-violation' && is_array($item['body'] ?? null)) {
                $b = $item['body'];
                $found[] = self::build($b['effectiveDirective'] ?? null, $b['blockedURL'] ?? null, $b['documentURL'] ?? null, $b['sourceFile'] ?? null, $b['lineNumber'] ?? null, $b['disposition'] ?? null);
            }
        }

        return $found;
    }

    /**
     * @param  array<mixed>  $b
     * @return array{directive: string, blocked: string, document: string, source: ?string, line: ?int, disposition: string}|null
     */
    private static function fromClassic(array $b): ?array
    {
        return self::build($b['effective-directive'] ?? $b['violated-directive'] ?? null, $b['blocked-uri'] ?? null, $b['document-uri'] ?? null, $b['source-file'] ?? null, $b['line-number'] ?? null, $b['disposition'] ?? null);
    }

    /** @return array{directive: string, blocked: string, document: string, source: ?string, line: ?int, disposition: string}|null */
    private static function build(mixed $directive, mixed $blocked, mixed $document, mixed $source, mixed $line, mixed $disposition): ?array
    {
        if (! is_string($directive) || $directive === '') {
            return null;
        }

        return [
            'directive' => self::text($directive),
            'blocked' => is_string($blocked) ? self::url($blocked) : '',
            'document' => is_string($document) ? self::url($document) : '',
            'source' => is_string($source) ? self::url($source) : null,
            'line' => is_int($line) ? $line : null,
            'disposition' => is_string($disposition) ? self::text($disposition) : 'enforce',
        ];
    }

    /** Sem query string nem fragmento: o endereço pode levar identificador de ticket ou token. */
    private static function url(string $value): string
    {
        return self::text((string) preg_replace('/[?#].*$/s', '', $value));
    }

    private static function text(string $value): string
    {
        return mb_substr($value, 0, self::MAX_TEXT);
    }
}
