<?php

declare(strict_types=1);

namespace LaravelSso\Client;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use LaravelSso\Client\Exceptions\ProtocolException;
use LaravelSso\Contracts\Identity;

final class IdTokenValidator
{
    /** @param array<string, mixed> $jwks */
    public function validate(string $jwt, array $jwks, string $nonce, ?int $maxAge = null, ?string $accessToken = null): Identity
    {
        try {
            // JWK::parseKeySet + Key enforces a known kid and a fixed RS256 policy.
            $keys = [];
            foreach ($jwks['keys'] ?? [] as $key) {
                if (($key['kty'] ?? null) === 'RSA' && ($key['alg'] ?? null) === 'RS256'
                    && ($key['use'] ?? 'sig') === 'sig' && is_string($key['kid'] ?? null)) {
                    $keys[] = $key;
                }
            }
            $claims = (array) JWT::decode($jwt, JWK::parseKeySet(['keys' => $keys]));
        } catch (\Throwable $e) {
            throw new ProtocolException('Invalid ID Token signature or dates.', 0, $e);
        }
        if (array_key_exists('at_hash', $claims) && (! is_string($claims['at_hash']) || $accessToken === null
            || ! hash_equals(JWT::urlsafeB64Encode(substr(hash('sha256', $accessToken, true), 0, 16)), $claims['at_hash']))) {
            throw new ProtocolException('Invalid access token hash.');
        }
        $now = time();
        $skew = (int) config('sso-client.clock_skew', 30);
        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        $client = config('sso-client.client_id');
        if (($claims['iss'] ?? null) !== config('sso-client.issuer')
            || $audiences === [] || count(array_filter($audiences, 'is_string')) !== count($audiences)
            || ! in_array($client, $audiences, true)
            || (count($audiences) > 1 && ($claims['azp'] ?? null) !== $client)
            || (isset($claims['azp']) && $claims['azp'] !== $client)
            || ! is_string($claims['sub'] ?? null) || $claims['sub'] === ''
            || array_key_exists('events', $claims)
            || ! is_string($claims['sid'] ?? null) || $claims['sid'] === ''
            || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
            || ! is_int($claims['iat'] ?? null) || $claims['iat'] > $now + $skew
            || $claims['iat'] < $now - (int) config('sso-client.max_token_age', 300)
            || $claims['exp'] <= $claims['iat']
            || ! is_int($claims['auth_time'] ?? null) || $claims['auth_time'] < 0 || $claims['auth_time'] > $claims['iat'] + $skew
            || ! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])
            || ($maxAge !== null && $now - $claims['auth_time'] > $maxAge + $skew)) {
            throw new ProtocolException('Invalid ID Token claims.');
        }

        return new Identity($claims['iss'], $claims['sub'], $claims['sid'], $claims['auth_time'],
            array_intersect_key($claims, array_flip(['name', 'email', 'email_verified'])));
    }
}
