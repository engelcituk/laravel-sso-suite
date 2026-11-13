<?php

declare(strict_types=1);

namespace LaravelSso\Client;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use LaravelSso\Client\Exceptions\ProtocolException;

final class TrustedHttp
{
    public function request(string $url): PendingRequest
    {
        $parts = parse_url($url);
        $httpAllowed = config('sso-client.allow_http') && ! app()->environment('production');
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ! isset($parts['host']) || ! in_array($parts['scheme'] ?? '', $httpAllowed ? ['https', 'http'] : ['https'], true)
            || ! in_array($url, config('sso-client.allowed_urls', []), true)) {
            throw new ProtocolException('Untrusted OIDC endpoint.');
        }

        return Http::acceptJson()->timeout(config('sso-client.timeout', 3))
            ->connectTimeout(config('sso-client.timeout', 3))->withoutRedirecting();
    }

    /** @return array<string, mixed> */
    public function get(string $url): array
    {
        try {
            $response = $this->request($url)->get($url);
        } catch (\Throwable $e) {
            throw new ProtocolException('OIDC endpoint unavailable.', 0, $e);
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new ProtocolException('OIDC endpoint failed.');
        }

        return $response->json();
    }
}
