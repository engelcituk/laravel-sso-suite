<?php

namespace LaravelSso\Tests;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use LaravelSso\Client\EloquentProvisioner;
use LaravelSso\Client\Events\IdentityProvisioned;
use LaravelSso\Client\Exceptions\ProtocolException;
use LaravelSso\Client\IdTokenValidator;
use LaravelSso\Client\TrustedHttp;
use LaravelSso\Contracts\Identity;
use LaravelSso\Server\Http\ProtocolController;
use LaravelSso\Server\SigningKeys;
use LaravelSso\Tests\Fixtures\LocalUser;
use PHPUnit\Framework\Attributes\DataProvider;

final class ClientTest extends TestCase
{
    private function claims(array $overrides = []): array
    {
        return array_merge(['iss' => 'https://identity.test', 'sub' => 'central-7', 'aud' => '00000000-0000-4000-8000-000000000001',
            'sid' => 'global-session', 'iat' => time(), 'exp' => time() + 300, 'auth_time' => time(),
            'nonce' => 'nonce-test', 'name' => 'Ada', 'email' => 'ada@example.test'], $overrides);
    }

    public static function invalidUserinfo(): array
    {
        return ['different subject' => [['sub' => 'another-user'], 200],
            'missing subject' => [['name' => 'Untrusted'], 200],
            'non-string subject' => [['sub' => 7], 200],
            'unavailable' => [['sub' => 'central-7'], 503]];
    }

    #[DataProvider('invalidUserinfo')]
    public function test_userinfo_must_match_validated_id_token_before_provisioning(array $body, int $status): void
    {
        config(['sso-client.provision' => 'automatic']);
        Http::preventStrayRequests();
        Http::fake([
            'https://identity.test/.well-known/openid-configuration' => Http::response(app(ProtocolController::class)->discovery()->getData(true)),
            'https://identity.test/oidc/jwks' => Http::response(app(SigningKeys::class)->jwks()),
            'https://identity.test/oidc/token' => Http::response(['access_token' => 'test-access', 'id_token' => app(SigningKeys::class)->sign($this->claims())]),
            'https://identity.test/oidc/userinfo' => Http::response($body, $status),
        ]);
        $this->withSession(['sso.transaction' => ['state' => 'state', 'nonce' => 'nonce-test', 'verifier' => str_repeat('v', 64), 'created' => time()]])
            ->get('/sso/callback?state=state&code=code&iss=https%3A%2F%2Fidentity.test')->assertStatus(400);
        $this->assertGuest('local');
        $this->assertSame(0, LocalUser::count());
        Http::assertSent(fn ($request) => $request->url() === 'https://identity.test/oidc/userinfo' && $request->hasHeader('Authorization', 'Bearer test-access'));
    }

    public function test_valid_identity_and_negative_jwt_claims(): void
    {
        $validator = app(IdTokenValidator::class);
        $keys = app(SigningKeys::class);
        $identity = $validator->validate($keys->sign($this->claims()), $keys->jwks(), 'nonce-test');
        $this->assertSame('central-7', $identity->subject);
        $this->assertSame('https://identity.test', $identity->issuer);
    }

    public static function invalidClaims(): array
    {
        return [
            'issuer' => [['iss' => 'https://evil.test']], 'audience' => [['aud' => 'another-client']],
            'expired' => [['exp' => 1]], 'future' => [['iat' => PHP_INT_MAX]],
            'nonce' => [['nonce' => 'wrong']], 'azp' => [['aud' => ['00000000-0000-4000-8000-000000000001', 'two']]],
            'subject' => [['sub' => '']], 'session' => [['sid' => null]], 'auth_time' => [['auth_time' => '123']],
        ];
    }

    #[DataProvider('invalidClaims')]
    public function test_rejects_invalid_claims(array $overrides): void
    {
        $keys = app(SigningKeys::class);
        $this->expectException(ProtocolException::class);
        app(IdTokenValidator::class)->validate($keys->sign($this->claims($overrides)), $keys->jwks(), 'nonce-test');
    }

    public function test_rejects_unknown_kid_wrong_algorithm_and_tampering(): void
    {
        $keys = app(SigningKeys::class);
        $private = file_get_contents(config('sso-server.private_key'));
        $tokens = [
            JWT::encode($this->claims(), $private, 'RS256', 'unknown'),
            JWT::encode($this->claims(), str_repeat('secret', 20), 'HS256', 'initial'),
            $keys->sign($this->claims()).'tampered',
            JWT::urlsafeB64Encode('{"alg":"none","kid":"initial"}').'.'.JWT::urlsafeB64Encode(json_encode($this->claims())).'.',
        ];
        foreach ($tokens as $token) {
            try {
                app(IdTokenValidator::class)->validate($token, $keys->jwks(), 'nonce-test');
                $this->fail('Untrusted JWT accepted.');
            } catch (ProtocolException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_provisioning_uses_issuer_subject_and_never_email_or_roles(): void
    {
        config(['sso-client.provision' => 'automatic']);
        $provisioner = app(EloquentProvisioner::class);
        $first = $provisioner->provision(new Identity('https://identity.test', 'one', 'sid', time(),
            ['name' => 'Ada', 'email' => 'same@example.test', 'role' => 'admin', 'password' => 'secret']));
        $again = $provisioner->provision(new Identity('https://identity.test', 'one', 'sid', time(), ['name' => 'Updated']));
        $other = $provisioner->provision(new Identity('https://identity.test', 'two', 'sid', time(), ['email' => 'same@example.test']));
        $this->assertSame($first->getAuthIdentifier(), $again->getAuthIdentifier());
        $this->assertNotSame($first->getAuthIdentifier(), $other->getAuthIdentifier());
        $this->assertSame('Updated', $again->name);
        $this->assertNull($again->role);
        $this->assertFalse(Schema::hasColumn('users', 'password'));
        $this->assertSame('', $again->getAuthPassword());
        $this->assertSame(2, LocalUser::count());
    }

    public function test_restricted_provisioning_rejects_unknown_identity(): void
    {
        $this->expectException(ProtocolException::class);
        app(EloquentProvisioner::class)->provision(new Identity('https://identity.test', 'unknown', 'sid', time()));
    }

    public function test_issuer_and_subject_keys_preserve_case_sensitive_identity(): void
    {
        config(['sso-client.provision' => 'automatic']);
        $ids = [];
        foreach ([['https://identity.test/Tenant', 'Subject'], ['https://identity.test/Tenant', 'subject'],
            ['https://identity.test/tenant', 'Subject']] as [$issuer, $subject]) {
            $ids[] = app(EloquentProvisioner::class)->provision(new Identity($issuer, $subject, 'sid', time()))->getAuthIdentifier();
        }
        $this->assertCount(3, array_unique($ids));
        $this->assertSame(3, LocalUser::count());
    }

    public function test_provisioning_event_runs_after_commit_and_is_discarded_on_rollback(): void
    {
        config(['sso-client.provision' => 'automatic']);
        Event::fake([IdentityProvisioned::class]);
        $identity = new Identity('https://identity.test', 'transactional', 'sid', time());
        DB::beginTransaction();
        app(EloquentProvisioner::class)->provision($identity);
        Event::assertNotDispatched(IdentityProvisioned::class);
        DB::rollBack();
        Event::assertNotDispatched(IdentityProvisioned::class);
        $this->assertSame(0, LocalUser::count());
        DB::transaction(fn () => app(EloquentProvisioner::class)->provision($identity), 5);
        Event::assertDispatchedTimes(IdentityProvisioned::class, 1);
        $this->assertSame(1, LocalUser::count());
    }

    public function test_local_access_policy_can_deny_existing_user(): void
    {
        LocalUser::create(['sso_issuer' => 'https://identity.test', 'sso_subject' => 'known']);
        config(['sso-client.allowed' => fn () => false]);
        $this->expectException(ProtocolException::class);
        app(EloquentProvisioner::class)->provision(new Identity('https://identity.test', 'known', 'sid', time()));
    }

    public function test_rejects_untrusted_http_endpoints_before_network_request(): void
    {
        Http::fake();
        foreach (['http://identity.test/oidc/token', 'https://evil.test/jwks',
            'https://user@identity.test/oidc/token', 'http://169.254.169.254/latest/meta-data/'] as $url) {
            try {
                app(TrustedHttp::class)->get($url);
                $this->fail('Untrusted endpoint accepted.');
            } catch (ProtocolException) {
                $this->addToAssertionCount(1);
            }
        }
        Http::assertNothingSent();
    }

    public static function tokenLifetimes(): array
    {
        return ['short token' => [45, 45], 'local bound' => [3600, 300]];
    }

    #[DataProvider('tokenLifetimes')]
    public function test_redirect_uses_pkce_and_callback_logs_in_local_guard(int $tokenLifetime, int $expectedLifetime): void
    {
        config(['sso-client.provision' => 'automatic']);
        $metadata = app(ProtocolController::class)->discovery()->getData(true);
        Http::fake([
            'https://identity.test/.well-known/openid-configuration' => Http::response($metadata),
            'https://identity.test/oidc/jwks' => Http::response(app(SigningKeys::class)->jwks()),
            'https://identity.test/oidc/userinfo' => Http::response(['sub' => 'central-7', 'name' => 'From UserInfo']),
        ]);
        $response = $this->get('/sso/login')->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $transaction = session('sso.transaction');
        $this->assertSame(JWT::urlsafeB64Encode(hash('sha256', $transaction['verifier'], true)), $query['code_challenge']);
        // The callback consumes its transaction before contacting the token endpoint.
        Http::fake(['https://identity.test/oidc/token' => Http::response([
            'access_token' => 'opaque-access-token',
            'expires_in' => $tokenLifetime,
            'id_token' => app(SigningKeys::class)->sign($this->claims(['nonce' => $query['nonce']])),
        ])]);
        $before = session()->getId();
        $issuedAt = time();
        $this->get('/sso/callback?'.http_build_query(['state' => $query['state'], 'code' => 'authorization-code', 'iss' => 'https://identity.test']))->assertRedirect('/');
        $this->assertTrue(Auth::guard('local')->check());
        $this->assertSame('central-7', Auth::guard('local')->user()->sso_subject);
        $this->assertSame('From UserInfo', Auth::guard('local')->user()->name);
        $this->assertNotSame($before, session()->getId());
        $this->assertNull(session('sso.transaction'));
        $expires = (int) DB::table('sso_local_sessions')->value('expires_at');
        $this->assertGreaterThanOrEqual($issuedAt + $expectedLifetime, $expires);
        $this->assertLessThanOrEqual(time() + $expectedLifetime, $expires);
        Http::assertSent(fn ($request) => $request->url() === 'https://identity.test/oidc/token'
            && $request['grant_type'] === 'authorization_code' && strlen($request['code_verifier']) === 64);
        $this->get('/sso/callback?'.http_build_query(['state' => $query['state'], 'code' => 'authorization-code']))->assertStatus(400);
    }

    public function test_strict_session_policy_fails_closed_when_provider_is_unavailable(): void
    {
        $user = LocalUser::create(['sso_issuer' => 'https://identity.test', 'sso_subject' => 'central-7']);
        Http::fake(['https://identity.test/oidc/session' => Http::response([], 503)]);
        $this->withSession([]);
        DB::table('sso_local_sessions')->insert(['session_hash' => hash('sha256', session()->getId()),
            'issuer' => 'https://identity.test', 'subject' => 'central-7', 'sid' => 'sid', 'revoked' => false]);
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
        $this->actingAs($user, 'local')->withSession(['sso.identity' => [
            'iss' => 'https://identity.test', 'sub' => 'central-7', 'sid' => 'sid', 'checked_at' => time(), 'access_token' => 'token',
        ]])->getJson('/protected')->assertUnauthorized();
        $this->assertGuest('local');
        $this->assertSame(1, LocalUser::count());
        Http::assertSentCount(1);
    }
}
