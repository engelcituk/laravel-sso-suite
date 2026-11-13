<?php

declare(strict_types=1);

namespace LaravelSso\Client;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use LaravelSso\Client\Exceptions\ProtocolException;

final class LogoutTokenValidator
{
    /** @param array<string, mixed> $jwks
     * @return array<string, mixed>
     */
    public function validate(string $jwt, array $jwks): array
    {
        try {
            $keys = array_values(array_filter($jwks['keys'] ?? [], static fn ($key) => ($key['alg'] ?? null) === 'RS256' && ($key['kty'] ?? null) === 'RSA' && ($key['use'] ?? 'sig') === 'sig'));
            $claims = (array) JWT::decode($jwt, JWK::parseKeySet(['keys' => $keys]));
        } catch (\Throwable $e) {
            throw new ProtocolException('Invalid Logout Token signature or dates.', 0, $e);
        }
        $events = $claims['events'] ?? null;
        $event = 'http://schemas.openid.net/event/backchannel-logout';
        $audiences = is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? null];
        if (($claims['iss'] ?? null) !== config('sso-client.issuer')
            || ! in_array(config('sso-client.client_id'), $audiences, true)
            || ! is_int($claims['iat'] ?? null) || $claims['iat'] > time() + config('sso-client.clock_skew')
            || $claims['iat'] < time() - config('sso-client.max_token_age')
            || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= time() || $claims['exp'] <= $claims['iat']
            || ! is_string($claims['jti'] ?? null) || $claims['jti'] === ''
            || isset($claims['nonce']) || array_key_exists('nonce', $claims)
            || ! is_object($events) || ! isset($events->{$event}) || ! is_object($events->{$event})
            || count((array) $events->{$event}) !== 0
            || (! is_string($claims['sid'] ?? null) && ! is_string($claims['sub'] ?? null))
            || (isset($claims['sid']) && ! is_string($claims['sid'])) || (isset($claims['sub']) && ! is_string($claims['sub']))
            || (isset($claims['sid']) && $claims['sid'] === '') || (isset($claims['sub']) && $claims['sub'] === '')) {
            throw new ProtocolException('Invalid Logout Token claims.');
        }

        return $claims;
    }
}
