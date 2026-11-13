<?php

declare(strict_types=1);

namespace LaravelSso\Server\Protocol;

use Symfony\Component\HttpFoundation\Response;

final class AuthorizationResponse
{
    /** @param array<string, string> $parameters */
    public function send(string $redirect, array $parameters, string $mode = 'query'): Response
    {
        $parameters['iss'] = config('sso-server.issuer');
        if ($mode === 'form_post') {
            $nonce = bin2hex(random_bytes(16));

            return response()->view('sso-server::form-post', compact('redirect', 'parameters', 'nonce'))
                ->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache')->header('Referrer-Policy', 'no-referrer')
                ->header('Content-Security-Policy', "default-src 'none'; script-src 'nonce-{$nonce}'; form-action ".$this->origin($redirect)."; frame-ancestors 'none'; base-uri 'none'");
        }

        return redirect()->away($redirect.(str_contains($redirect, '?') ? '&' : '?').http_build_query($parameters, '', '&', PHP_QUERY_RFC3986))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
