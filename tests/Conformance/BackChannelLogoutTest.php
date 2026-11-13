<?php

namespace LaravelSso\Tests\Conformance;

use LaravelSso\Client\Exceptions\ProtocolException;
use LaravelSso\Client\LogoutTokenValidator;
use LaravelSso\Server\SigningKeys;
use LaravelSso\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('conformance')]
final class BackChannelLogoutTest extends TestCase
{
    private function claims(array $extra = []): array
    {
        return array_merge(['iss' => 'https://identity.test', 'aud' => 'client-one', 'sub' => 'subject',
            'iat' => time(), 'jti' => 'delivery', 'events' => ['http://schemas.openid.net/event/backchannel-logout' => (object) []]], $extra);
    }

    public function test_backchannel_2_4_exp_and_sid_are_optional_with_subject_and_bounded_iat(): void
    {
        $keys = app(SigningKeys::class);
        $claims = app(LogoutTokenValidator::class)->validate($keys->sign($this->claims()), $keys->jwks());
        $this->assertSame('subject', $claims['sub']);
        $this->assertArrayNotHasKey('exp', $claims);
        $this->assertArrayNotHasKey('sid', $claims);
    }

    public static function invalidTokens(): array
    {
        return ['audience must contain strings' => [['aud' => ['client-one', 123]]],
            'null sid is not omitted' => [['sid' => null]], 'null sub is invalid' => [['sub' => null, 'sid' => 'session']],
            'nonce even null forbidden' => [['nonce' => null]], 'null exp invalid' => [['exp' => null]],
            'old token without exp rejected' => [['iat' => 1]], 'missing logout event' => [['events' => (object) []]],
            'nonempty event payload' => [['events' => ['http://schemas.openid.net/event/backchannel-logout' => (object) ['x' => 1]]]]];
    }

    #[DataProvider('invalidTokens')]
    public function test_backchannel_2_6_requires_claim_shapes_event_and_freshness(array $extra): void
    {
        $keys = app(SigningKeys::class);
        $this->expectException(ProtocolException::class);
        app(LogoutTokenValidator::class)->validate($keys->sign($this->claims($extra)), $keys->jwks());
    }
}
