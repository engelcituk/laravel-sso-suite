<?php

namespace LaravelSso\Tests;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use LaravelSso\Server\SigningKeys;

final class KeyRotationTest extends TestCase
{
    public function test_old_and_new_public_keys_validate_during_overlap_without_publishing_private_material(): void
    {
        $keys = app(SigningKeys::class);
        $old = $keys->sign(['iat' => time(), 'exp' => time() + 300]);
        $resource = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($resource, $private);
        file_put_contents(__DIR__.'/.keys/next-private.pem', $private);
        file_put_contents(__DIR__.'/.keys/next-public.pem', openssl_pkey_get_details($resource)['key']);
        config(['sso-server.signing_kid' => 'next', 'sso-server.private_key' => __DIR__.'/.keys/next-private.pem',
            'sso-server.public_keys.next' => __DIR__.'/.keys/next-public.pem']);
        $new = $keys->sign(['iat' => time(), 'exp' => time() + 300]);
        $jwks = $keys->jwks();
        $this->assertCount(2, $jwks['keys']);
        $this->assertNotNull(JWT::decode($old, JWK::parseKeySet($jwks)));
        $this->assertNotNull(JWT::decode($new, JWK::parseKeySet($jwks)));
        foreach ($jwks['keys'] as $key) {
            $this->assertArrayNotHasKey('d', $key);
            $this->assertArrayNotHasKey('p', $key);
            $this->assertArrayNotHasKey('q', $key);
        }
    }
}
