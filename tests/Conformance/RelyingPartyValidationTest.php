<?php

namespace LaravelSso\Tests\Conformance;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use LaravelSso\Client\Exceptions\ProtocolException;
use LaravelSso\Client\IdTokenValidator;
use LaravelSso\Server\Http\ProtocolController;
use LaravelSso\Server\SigningKeys;
use LaravelSso\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('conformance')]
final class RelyingPartyValidationTest extends TestCase
{
    private function claims(array $extra = []): array
    {
        return array_merge(['iss' => 'https://identity.test', 'aud' => 'client-one', 'sub' => 'subject',
            'sid' => 'global', 'iat' => time(), 'exp' => time() + 300, 'auth_time' => time(), 'nonce' => 'nonce'], $extra);
    }

    public function test_core_3_1_3_8_validates_at_hash_over_exact_access_token(): void
    {
        $claims = $this->claims(['at_hash' => JWT::urlsafeB64Encode(substr(hash('sha256', 'access-token', true), 0, 16))]);
        $keys = app(SigningKeys::class);
        $identity = app(IdTokenValidator::class)->validate($keys->sign($claims), $keys->jwks(), 'nonce', null, 'access-token');
        $this->assertSame('subject', $identity->subject);
        $this->expectException(ProtocolException::class);
        app(IdTokenValidator::class)->validate($keys->sign($claims), $keys->jwks(), 'nonce', null, 'different-access-token');
    }

    public static function invalidClaims(): array
    {
        return ['null hash' => [['at_hash' => null]], 'wrong hash' => [['at_hash' => 'wrong']],
            'audience types' => [['aud' => ['client-one', 123], 'azp' => 'client-one']],
            'missing azp' => [['aud' => ['client-one', 'another']]],
            'wrong azp' => [['aud' => ['client-one', 'another'], 'azp' => 'another']],
            'JWT confusion' => [['events' => (object) []]]];
    }

    #[DataProvider('invalidClaims')]
    public function test_core_3_1_3_7_additional_id_token_rules_are_enforced(array $extra): void
    {
        $keys = app(SigningKeys::class);
        $this->expectException(ProtocolException::class);
        app(IdTokenValidator::class)->validate($keys->sign($this->claims($extra)), $keys->jwks(), 'nonce', null, 'access-token');
    }

    public function test_core_3_1_3_7_max_age_is_checked_in_signed_auth_time(): void
    {
        $keys = app(SigningKeys::class);
        $this->expectException(ProtocolException::class);
        app(IdTokenValidator::class)->validate($keys->sign($this->claims(['auth_time' => time() - 1000])), $keys->jwks(), 'nonce', 60);
    }

    public static function responseIssuers(): array
    {
        return ['missing' => [null], 'wrong' => ['https://evil.test']];
    }

    #[DataProvider('responseIssuers')]
    public function test_rfc9207_missing_or_wrong_authorization_issuer_never_reaches_token_endpoint(?string $issuer): void
    {
        $metadata = app(ProtocolController::class)->discovery()->getData(true);
        Http::preventStrayRequests();
        Http::fake(['https://identity.test/.well-known/openid-configuration' => Http::response($metadata)]);
        $this->withSession(['sso.transaction' => ['state' => 'state', 'nonce' => 'nonce', 'verifier' => str_repeat('v', 64), 'created' => time()]])
            ->get('/sso/callback?'.http_build_query(array_filter(['state' => 'state', 'code' => 'code', 'iss' => $issuer], fn ($v) => $v !== null)))
            ->assertStatus(400);
        $this->assertGuest('local');
        Http::assertSentCount(1); // Discovery only; neither code nor secret goes to a token endpoint.
    }
}
