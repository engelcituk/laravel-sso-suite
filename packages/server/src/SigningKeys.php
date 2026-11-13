<?php

declare(strict_types=1);

namespace LaravelSso\Server;

use Firebase\JWT\JWT;

final class SigningKeys
{
    /** @param array<string, mixed> $claims */
    public function sign(array $claims): string
    {
        $kid = config('sso-server.signing_kid');
        if (! isset(config('sso-server.public_keys')[$kid])) {
            throw new \RuntimeException('The signing kid must have a published public key.');
        }
        $key = file_get_contents(config('sso-server.private_key'));
        if ($key === false) {
            throw new \RuntimeException('Missing OIDC signing key.');
        }

        return JWT::encode($claims, $key, 'RS256', $kid);
    }

    /** @return array{keys: list<array<string, string>>} */
    public function jwks(): array
    {
        $keys = [];
        foreach (config('sso-server.public_keys') as $kid => $path) {
            $pem = file_get_contents($path);
            $resource = $pem === false ? false : openssl_pkey_get_public($pem);
            $details = $resource === false ? false : openssl_pkey_get_details($resource);
            if (! $details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
                throw new \RuntimeException('OIDC requires RSA keys of at least 2048 bits.');
            }
            $keys[] = ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => (string) $kid,
                'n' => JWT::urlsafeB64Encode($details['rsa']['n']), 'e' => JWT::urlsafeB64Encode($details['rsa']['e'])];
        }

        return ['keys' => $keys];
    }
}
