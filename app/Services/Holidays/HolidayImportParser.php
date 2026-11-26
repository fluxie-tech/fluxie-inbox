<?php

namespace App\Services\Holidays;

use App\Enums\HolidayKind;
use App\Rules\NoControlChars;
use Carbon\CarbonImmutable;
use JsonException;

/** Lê o JSON de feriados (puro ou cercado por bloco Markdown) e devolve uma linha por item, com o erro dela quando houver. */
class HolidayImportParser
{
    public const MAX_ITEMS = 500;

    public const NAME_LIMIT = 120;

    private const FILE_ERROR = 'O arquivo não é uma lista JSON de feriados.';

    /** @var list<HolidayKind> tipos aceitos na importação: o manual só nasce no cadastro à mão */
    private const IMPORTABLE = [HolidayKind::NACIONAL, HolidayKind::ESTADUAL, HolidayKind::MUNICIPAL, HolidayKind::FACULTATIVO];

    /**
     * @param  list<string>  $existingDates  datas `Y-m-d` já cadastradas
     * @return array{error: ?string, rows: list<HolidayImportRow>}
     */
    public function parse(string $contents, array $existingDates): array
    {
        try {
            $items = json_decode($this->extractJson($contents), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->fileError(self::FILE_ERROR);
        }

        if (! is_array($items) || ! array_is_list($items) || $items === []) {
            return $this->fileError(self::FILE_ERROR);
        }

        if (count($items) > self::MAX_ITEMS) {
            return $this->fileError('O arquivo passa de '.self::MAX_ITEMS.' feriados.');
        }

        $seen = [];
        $rows = [];

        foreach ($items as $item) {
            $row = $this->row(is_array($item) ? $item : [], $existingDates);
            $rows[] = $this->rejectRepeated($row, $seen);
        }

        return ['error' => null, 'rows' => $rows];
    }

    /** @return array{error: string, rows: list<HolidayImportRow>} */
    private function fileError(string $message): array
    {
        return ['error' => $message, 'rows' => []];
    }

    /** O bloco Markdown vence; sem ele, do primeiro `[` ao último `]`; sem nenhum, o texto todo. */
    private function extractJson(string $contents): string
    {
        if (preg_match('/```(?:json)?\s*(.*?)```/s', $contents, $block) === 1) {
            return $block[1];
        }

        $start = strpos($contents, '[');
        $end = strrpos($contents, ']');

        return $start !== false && $end !== false && $end > $start ? substr($contents, $start, $end - $start + 1) : $contents;
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @param  list<string>  $existingDates
     */
    private function row(array $item, array $existingDates): HolidayImportRow
    {
        $date = $this->parseDate($this->text($item, 'data', 'date'));
        $name = trim($this->text($item, 'nome', 'name'));
        $kind = HolidayKind::tryFrom(mb_strtolower(trim($this->text($item, 'tipo', 'type'))));
        $kind = in_array($kind, self::IMPORTABLE, true) ? $kind : null;

        $error = match (true) {
            $date === null => 'Data inválida.',
            $name === '' => 'Informe o nome do feriado.',
            mb_strlen($name) > self::NAME_LIMIT => 'O nome passa de '.self::NAME_LIMIT.' caracteres.',
            ! NoControlChars::isClean($name) => 'O nome tem caracteres que não podem ser usados.',
            $kind === null => 'Tipo desconhecido (use nacional, estadual, municipal ou facultativo).',
            default => null,
        };

        return new HolidayImportRow($date, $name, $kind, $error, $date !== null && in_array($date, $existingDates, true));
    }

    /** @param  array<array-key, mixed>  $item */
    private function text(array $item, string $key, string $english): string
    {
        $value = $item[$key] ?? $item[$english] ?? '';

        return is_string($value) ? $value : '';
    }

    /** `AAAA-MM-DD` ou `DD/MM/AAAA`; a volta ao texto igual à entrada recusa 30/02. */
    private function parseDate(string $text): ?string
    {
        $text = trim($text);

        foreach (['Y-m-d', 'd/m/Y'] as $format) {
            // hasFormat só confere o formato; a volta ao texto igual à entrada recusa 30/02.
            $date = CarbonImmutable::hasFormat($text, $format) ? CarbonImmutable::createFromFormat('!'.$format, $text) : null;

            if ($date !== null && $date->format($format) === $text) {
                return $date->toDateString();
            }
        }

        return null;
    }

    /** @param  array<string, true>  $seen datas já vistas no arquivo */
    private function rejectRepeated(HolidayImportRow $row, array &$seen): HolidayImportRow
    {
        // "Vale a primeira" é a primeira linha sem erro: linha com erro não reserva a data.
        if ($row->date === null || $row->error !== null) {
            return $row;
        }

        $repeated = isset($seen[$row->date]);
        $seen[$row->date] = true;

        return $repeated ? $this->withError($row, 'Data repetida no arquivo.') : $row;
    }

    private function withError(HolidayImportRow $row, string $error): HolidayImportRow
    {
        return new HolidayImportRow($row->date, $row->name, $row->kind, $error, $row->exists);
    }
}
