<?php

namespace App\Http\Controllers;

use App\Services\Security\CspReports;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Coletor interno das violações da CSP: sem login nem sessão, responde 204 a qualquer corpo (o navegador não lê a resposta). */
class CspReportController extends Controller
{
    public function __invoke(Request $request, CspReports $reports): Response
    {
        $max = config()->integer('security.csp_report_max_bytes');

        // O Content-Length recusa antes de ler o corpo; o tamanho real vale para quem não o envia.
        $body = (int) $request->header('Content-Length') > $max ? '' : $request->getContent();

        if ($body !== '' && strlen($body) <= $max) {
            foreach ($reports->parse($body, (string) $request->header('Content-Type')) as $violation) {
                $reports->record($violation);
            }
        }

        return response()->noContent();
    }
}
