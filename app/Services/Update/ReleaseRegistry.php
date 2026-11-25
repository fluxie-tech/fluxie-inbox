<?php

namespace App\Services\Update;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * As consultas de rede do pré-voo fora do Coolify: o GHCR (a imagem existe?) e o GitHub (o asset da Release). Nunca lança por
 * falha de rede: devolve texto em português ou `null`.
 */
final class ReleaseRegistry
{
    /** Sem este `Accept` o GHCR responde 404 até para uma tag que existe. */
    private const MANIFEST_ACCEPT = 'application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.docker.distribution.manifest.v2+json, application/vnd.oci.image.manifest.v1+json';

    public function imageError(Version $target): ?string
    {
        $path = Str::after(config()->string('fluxie.image'), 'ghcr.io/');
        $missing = "A imagem {$target} não está publicada no GHCR.";

        try {
            $token = Http::timeout(10)->get('https://ghcr.io/token', ['scope' => "repository:{$path}:pull"])->json('token');

            if (! is_string($token) || $token === '') {
                return 'Não foi possível consultar o GHCR agora.';
            }

            $manifest = Http::withToken($token)->withHeaders(['Accept' => self::MANIFEST_ACCEPT])->timeout(10)->get("https://ghcr.io/v2/{$path}/manifests/{$target}");
        } catch (ConnectionException) {
            return 'Sem conexão com o GHCR.';
        }

        return match (true) {
            $manifest->ok() => null,
            $manifest->status() === 404 => $missing,
            default => 'Não foi possível consultar o GHCR agora (HTTP '.$manifest->status().').',
        };
    }

    public function download(string $url): ?string
    {
        try {
            $response = Http::withUserAgent('Fluxie-Inbox')->timeout(15)->get($url);
        } catch (ConnectionException) {
            return null;
        }

        return $this->body($response);
    }

    private function body(Response $response): ?string
    {
        return $response->ok() && trim($response->body()) !== '' ? $response->body() : null;
    }
}
