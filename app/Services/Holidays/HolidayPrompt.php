<?php

namespace App\Services\Holidays;

use Carbon\CarbonImmutable;

/** O texto que o admin cola numa IA para obter o JSON de feriados que o importador lê. */
final class HolidayPrompt
{
    /** @param  string  $from  `Y-m-d` */
    public static function for(string $city, string $uf, string $from, string $to): string
    {
        $from = CarbonImmutable::parse($from)->format('d/m/Y');
        $to = CarbonImmutable::parse($to)->format('d/m/Y');

        return <<<TEXT
        Liste todos os feriados e pontos facultativos de {$city}/{$uf}, Brasil, entre {$from} e {$to}: os nacionais, os estaduais de {$uf} e os municipais de {$city}, mais os pontos facultativos nacionais (Carnaval, Quarta-feira de Cinzas, Corpus Christi quando não for feriado na cidade, véspera de Natal e de Ano-Novo).
        Responda só com um JSON, sem nenhum texto antes ou depois, neste formato:
        [{"data": "AAAA-MM-DD", "nome": "Nome do feriado", "tipo": "nacional"}]
        O campo "tipo" é um destes: nacional, estadual, municipal, facultativo.
        Não invente: se não tiver certeza de um feriado municipal, deixe-o de fora.
        TEXT;
    }
}
