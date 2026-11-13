<?php

declare(strict_types=1);

namespace LaravelSso\Client;

use LaravelSso\Client\Exceptions\ProtocolException;

final class Provider
{
    public function __construct(private TrustedHttp $http) {}

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        $issuer = config('sso-client.issuer');
        $data = $this->http->get($issuer.'/.well-known/openid-configuration');
        if (($data['issuer'] ?? null) !== $issuer) {
            throw new ProtocolException('Discovery issuer mismatch.');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri', 'userinfo_endpoint'] as $key) {
            if (! is_string($data[$key] ?? null)) {
                throw new ProtocolException('Missing discovery endpoint.');
            }
            $this->http->request($data[$key]);
        }
        if (! in_array('RS256', $data['id_token_signing_alg_values_supported'] ?? [], true)
            || ! in_array('S256', $data['code_challenge_methods_supported'] ?? [], true)) {
            throw new ProtocolException('Provider lacks required RS256 / S256 support.');
        }

        return $data;
    }
}
