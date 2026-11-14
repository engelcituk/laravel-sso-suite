<?php

namespace LaravelSso\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LaravelSso\Client\Exceptions\ProtocolException;
use LaravelSso\Client\LogoutTokenValidator;
use LaravelSso\Server\GlobalSessions;
use LaravelSso\Server\Http\ProtocolController;
use LaravelSso\Server\LogoutDeliveries;
use LaravelSso\Server\SigningKeys;
use LaravelSso\Tests\Fixtures\CentralUser;
use LaravelSso\Tests\Fixtures\LocalUser;
use PHPUnit\Framework\Attributes\DataProvider;

final class LogoutTest extends TestCase
{
    private function claims(array $overrides = []): array
    {
        return array_merge(['iss' => 'https://identity.test', 'aud' => '00000000-0000-4000-8000-000000000001', 'sub' => 'subject-one',
            'sid' => 'global-one', 'iat' => time(), 'exp' => time() + 120, 'jti' => 'delivery-one',
            'events' => ['http://schemas.openid.net/event/backchannel-logout' => (object) []]], $overrides);
    }

    public function test_backchannel_revokes_only_matching_sessions_and_acknowledges_duplicates(): void
    {
        $metadata = app(ProtocolController::class)->discovery()->getData(true);
        Http::fake([
            'https://identity.test/.well-known/openid-configuration' => Http::response($metadata),
            'https://identity.test/oidc/jwks' => Http::response(app(SigningKeys::class)->jwks()),
        ]);
        foreach (['global-one', 'another-device'] as $sid) {
            DB::table('sso_local_sessions')->insert(['session_hash' => hash('sha256', $sid),
                'issuer' => 'https://identity.test', 'subject' => 'subject-one', 'sid' => $sid, 'revoked' => false]);
        }
        $token = app(SigningKeys::class)->sign($this->claims());
        $this->post('/sso/backchannel-logout', ['logout_token' => $token])->assertOk();
        $this->assertEquals(1, DB::table('sso_local_sessions')->where('sid', 'global-one')->value('revoked'));
        $this->assertEquals(0, DB::table('sso_local_sessions')->where('sid', 'another-device')->value('revoked'));
        // A replay must not invalidate a newly recorded session a second time.
        DB::table('sso_local_sessions')->where('sid', 'global-one')->update(['revoked' => false]);
        $this->post('/sso/backchannel-logout', ['logout_token' => $token])->assertOk();
        $this->assertEquals(0, DB::table('sso_local_sessions')->where('sid', 'global-one')->value('revoked'));
        $this->assertSame(1, DB::table('sso_logout_receipts')->count());
    }

    public function test_logout_token_rejects_nonce_wrong_event_audience_and_expiry(): void
    {
        $keys = app(SigningKeys::class);
        foreach ([['nonce' => 'not-allowed'], ['events' => ['other-event' => (object) []]],
            ['aud' => 'other-client'], ['exp' => 1], ['sid' => null, 'sub' => null], ['jti' => '']] as $overrides) {
            try {
                app(LogoutTokenValidator::class)->validate($keys->sign($this->claims($overrides)), $keys->jwks());
                $this->fail('Invalid logout token accepted.');
            } catch (ProtocolException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_backchannel_sid_comparison_is_case_sensitive(): void
    {
        Http::fake([
            'https://identity.test/.well-known/openid-configuration' => Http::response(app(ProtocolController::class)->discovery()->getData(true)),
            'https://identity.test/oidc/jwks' => Http::response(app(SigningKeys::class)->jwks()),
        ]);
        foreach (['Session', 'session'] as $sid) {
            DB::table('sso_local_sessions')->insert(['session_hash' => hash('sha256', $sid), 'issuer' => 'https://identity.test',
                'subject' => 'subject-one', 'sid' => $sid, 'revoked' => false]);
        }
        $this->post('/sso/backchannel-logout', ['logout_token' => app(SigningKeys::class)->sign($this->claims(['sid' => 'Session']))])->assertOk();
        $this->assertTrue((bool) DB::table('sso_local_sessions')->where('sid', 'Session')->value('revoked'));
        $this->assertFalse((bool) DB::table('sso_local_sessions')->where('sid', 'session')->value('revoked'));
    }

    public function test_durable_delivery_retries_after_outage_without_duplicate_outbox_rows(): void
    {
        $user = CentralUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => bcrypt('secret')]);
        DB::table('sso_sessions')->insert(['sid' => 'global-one', 'subject' => (string) $user->id,
            'auth_time' => time(), 'last_seen' => time(), 'expires_at' => time() + 3600, 'revoked' => false]);
        DB::table('sso_clients')->insert(['client_id' => '00000000-0000-4000-8000-000000000001', 'auto_approve' => true,
            'backchannel_logout_uri' => 'https://one.test/sso/backchannel-logout']);
        DB::table('sso_token_sessions')->insert(['token_id' => str_repeat('a', 80), 'sid' => 'global-one', 'client_id' => '00000000-0000-4000-8000-000000000001']);
        config(['sso-server.backchannel_allowed_urls' => ['https://one.test/sso/backchannel-logout']]);
        app(GlobalSessions::class)->revoke('global-one');
        app(GlobalSessions::class)->revoke('global-one');
        $this->assertSame(1, DB::table('sso_logout_deliveries')->count());
        Http::fakeSequence()->push([], 503)->push([], 200);
        $deliveries = app(LogoutDeliveries::class);
        $this->assertTrue($deliveries->deliverOne());
        $this->assertNull(DB::table('sso_logout_deliveries')->value('delivered_at'));
        $this->assertFalse($deliveries->deliverOne());
        DB::table('sso_logout_deliveries')->update(['next_attempt' => time() - 1]);
        $this->assertTrue($deliveries->deliverOne());
        $this->assertNotNull(DB::table('sso_logout_deliveries')->value('delivered_at'));
        $this->assertEquals(2, DB::table('sso_logout_deliveries')->value('attempts'));
        Http::assertSentCount(2);
    }

    public static function inactiveIndexes(): array
    {
        return ['revoked' => [true, null], 'expired' => [false, 1]];
    }

    #[DataProvider('inactiveIndexes')]
    public function test_inactive_local_index_denies_access_even_with_fresh_central_cache(bool $revoked, ?int $expires): void
    {
        $user = LocalUser::create(['sso_issuer' => 'https://identity.test', 'sso_subject' => 'subject-one']);
        $this->withSession([]);
        DB::table('sso_local_sessions')->insert(['session_hash' => hash('sha256', session()->getId()),
            'issuer' => 'https://identity.test', 'subject' => 'subject-one', 'sid' => 'global-one', 'revoked' => $revoked, 'expires_at' => $expires]);
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
        Http::fake();
        $this->actingAs($user, 'local')->withSession(['sso.identity' => ['iss' => 'https://identity.test', 'sub' => 'subject-one',
            'sid' => 'global-one', 'checked_at' => time(), 'access_token' => 'token']])->getJson('/protected')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_rp_initiated_logout_accepts_signed_expired_hint_only_for_matching_central_session(): void
    {
        $user = CentralUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => bcrypt('secret')]);
        DB::table('sso_sessions')->insert(['sid' => 'global-one', 'subject' => (string) $user->id,
            'auth_time' => time() - 1000, 'last_seen' => time(), 'expires_at' => time() + 3600, 'revoked' => false]);
        DB::table('sso_clients')->insert(['client_id' => '00000000-0000-4000-8000-000000000001', 'auto_approve' => true,
            'post_logout_redirect_uris' => json_encode(['https://one.test/logged-out'])]);
        DB::table('sso_token_sessions')->insert(['token_id' => str_repeat('a', 80), 'sid' => 'global-one', 'client_id' => '00000000-0000-4000-8000-000000000001']);
        $hint = app(SigningKeys::class)->sign(['iss' => 'https://identity.test', 'aud' => '00000000-0000-4000-8000-000000000001',
            'sub' => (string) $user->id, 'sid' => 'global-one', 'iat' => time() - 1000, 'exp' => time() - 500]);
        $this->actingAs($user, 'web')->withSession(['sso.sid' => 'global-one'])->get('/oidc/logout?'.http_build_query([
            'id_token_hint' => $hint, 'post_logout_redirect_uri' => 'https://evil.test',
        ]))->assertStatus(400);
        $this->get('/oidc/logout?'.http_build_query(['id_token_hint' => $hint,
            'post_logout_redirect_uri' => 'https://one.test/logged-out', 'state' => 'logout-state']))->assertOk();
        $pending = session('sso.logout');
        $this->post('/oidc/logout/confirm', ['transaction' => $pending['transaction']])->assertRedirect('https://one.test/logged-out?state=logout-state');
        $this->assertEquals(1, DB::table('sso_sessions')->where('sid', 'global-one')->value('revoked'));
        $this->assertGuest('web');
    }
}
