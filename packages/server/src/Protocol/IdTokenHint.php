<?php

declare(strict_types=1);

namespace LaravelSso\Server\Protocol;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use LaravelSso\Server\SigningKeys;

final class IdTokenHint
{
    /** A signed hint never authenticates its bearer. Expiry is deliberately optional ONLY here.
     * @return array<string, mixed>
     */
    public function validate(string $hint): array
    {
        try {
            try {
                $claims = (array) JWT::decode($hint, JWK::parseKeySet(app(SigningKeys::class)->jwks()));
            } catch (ExpiredException $e) {
                $claims = (array) $e->getPayload(); // Library has already verified signature and nbf/iat.
            }
        } catch (\Throwable) {
            throw new InvalidRequest;
        }
        $audiences = is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? null];
        if (($claims['iss'] ?? null) !== config('sso-server.issuer')
            || ! is_string($claims['sub'] ?? null) || $claims['sub'] === ''
            || $audiences === [] || count(array_filter($audiences, 'is_string')) !== count($audiences)
            || ! is_int($claims['exp'] ?? null) || ! is_int($claims['iat'] ?? null) || $claims['exp'] <= $claims['iat']
            || array_key_exists('events', $claims) || (isset($claims['sid']) && ! is_string($claims['sid']))) {
            throw new InvalidRequest;
        }

        return $claims;
    }
}
