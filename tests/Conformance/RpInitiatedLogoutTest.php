<?php

namespace LaravelSso\Tests\Conformance;

use Illuminate\Support\Facades\DB;
use LaravelSso\Server\SigningKeys;
use LaravelSso\Tests\Fixtures\CentralUser;
use LaravelSso\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('conformance')]
final class RpInitiatedLogoutTest extends TestCase
{
    private const CLIENT = '11111111-1111-4111-8111-111111111111';

    private function fixture(): array
    {
        $user = CentralUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => bcrypt('secret')]);
        DB::table('sso_clients')->insert(['client_id' => self::CLIENT, 'auto_approve' => true,
            'post_logout_redirect_uris' => json_encode(['https://one.test/signed-out?tenant=1'])]);
        $this->sessionRow('hinted', (string) $user->id);
        $hint = app(SigningKeys::class)->sign(['iss' => 'https://identity.test', 'sub' => (string) $user->id,
            'aud' => self::CLIENT, 'sid' => 'hinted', 'iat' => time() - 1200, 'exp' => time() - 600]);

        return [$user, $hint];
    }

    private function sessionRow(string $sid, string $subject): void
    {
        DB::table('sso_sessions')->insert(['sid' => $sid, 'subject' => $subject, 'auth_time' => time() - 1200,
            'last_seen' => time(), 'expires_at' => time() + 3600, 'revoked' => false]);
        DB::table('sso_token_sessions')->insert(['token_id' => hash('sha256', $sid), 'sid' => $sid, 'client_id' => self::CLIENT]);
    }

    private function approve(array $extra = [])
    {
        return $this->post('/oidc/logout/confirm', ['transaction' => session('sso.logout.transaction')] + $extra);
    }

    public function test_logout_2_expired_signed_hint_without_op_cookie_requires_confirmation_then_revokes(): void
    {
        [, $hint] = $this->fixture();
        $this->get('/oidc/logout?'.http_build_query(['id_token_hint' => $hint]))->assertOk()->assertSee('Cerrar sesión global');
        $this->assertEquals(0, DB::table('sso_sessions')->value('revoked'));
        $this->assertGuest('web');
        $this->approve()->assertOk();
        $this->assertEquals(1, DB::table('sso_sessions')->value('revoked'));
        $this->assertGuest('web'); // A hint never authenticates its bearer.
    }

    public function test_logout_2_no_hint_uses_current_session_and_registered_client_redirect(): void
    {
        [$user] = $this->fixture();
        $this->actingAs($user)->withSession(['sso.sid' => 'hinted'])->get('/oidc/logout?'.http_build_query([
            'client_id' => self::CLIENT, 'post_logout_redirect_uri' => 'https://one.test/signed-out?tenant=1', 'state' => 'opaque & state',
        ]))->assertOk();
        $this->approve()->assertRedirect('https://one.test/signed-out?tenant=1&state=opaque%20%26%20state');
        $this->assertEquals(1, DB::table('sso_sessions')->value('revoked'));
        $this->assertGuest('web');
    }

    public function test_logout_2_without_hint_or_client_still_prompts_and_never_redirects_arbitrarily(): void
    {
        $this->fixture();
        $this->get('/oidc/logout')->assertOk();
        $response = $this->approve()->assertOk();
        $this->assertFalse($response->headers->has('Location'));
        $this->assertEquals(0, DB::table('sso_sessions')->value('revoked'));
    }

    public function test_logout_2_accepts_external_post_and_can_cancel_without_revocation(): void
    {
        [, $hint] = $this->fixture();
        $this->post('/oidc/logout', ['id_token_hint' => $hint, 'ui_locales' => 'unsupported-ZZ'])->assertOk();
        $this->approve(['approve' => '0'])->assertOk()->assertSee('cancelado');
        $this->assertEquals(0, DB::table('sso_sessions')->value('revoked'));
        $this->post('/oidc/logout/confirm', ['transaction' => str_repeat('a', 64)])->assertStatus(400);
    }

    public function test_logout_2_mismatched_current_user_is_prompted_and_other_users_session_is_not_revoked(): void
    {
        [, $hint] = $this->fixture();
        $other = CentralUser::create(['name' => 'Grace', 'email' => 'grace@example.test', 'password' => bcrypt('secret')]);
        $this->sessionRow('current', (string) $other->id);
        $this->actingAs($other)->withSession(['sso.sid' => 'current'])
            ->get('/oidc/logout?'.http_build_query(['id_token_hint' => $hint]))->assertOk();
        $this->approve()->assertOk();
        $this->assertEquals(1, DB::table('sso_sessions')->where('sid', 'current')->value('revoked'));
        $this->assertEquals(0, DB::table('sso_sessions')->where('sid', 'hinted')->value('revoked'));
    }

    public static function invalidRedirects(): array
    {
        return ['unregistered' => ['https://evil.test'], 'prefix attack' => ['https://one.test/signed-out.evil'],
            'query changes' => ['https://one.test/signed-out?tenant=2'], 'fragment' => ['https://one.test/signed-out?tenant=1#x']];
    }

    #[DataProvider('invalidRedirects')]
    public function test_logout_3_rejects_non_exact_post_logout_redirects(string $redirect): void
    {
        [, $hint] = $this->fixture();
        $response = $this->get('/oidc/logout?'.http_build_query(['id_token_hint' => $hint, 'post_logout_redirect_uri' => $redirect]))->assertStatus(400);
        $this->assertFalse($response->headers->has('Location'));
        $this->assertEquals(0, DB::table('sso_sessions')->value('revoked'));
    }

    public function test_logout_2_rejects_mismatched_client_id_even_for_a_valid_hint(): void
    {
        [, $hint] = $this->fixture();
        $this->get('/oidc/logout?'.http_build_query(['id_token_hint' => $hint, 'client_id' => 'other-rp']))->assertStatus(400);
    }

    public function test_logout_4_invalid_signature_never_logs_out_the_current_user(): void
    {
        [$user, $hint] = $this->fixture();
        $this->actingAs($user)->withSession(['sso.sid' => 'hinted'])
            ->get('/oidc/logout?'.http_build_query(['id_token_hint' => $hint.'altered']))->assertStatus(400);
        $this->assertAuthenticatedAs($user);
        $this->assertEquals(0, DB::table('sso_sessions')->value('revoked'));
    }

    public function test_logout_2_rejects_very_old_hints_and_confirmation_after_account_switch(): void
    {
        [$user, $hint] = $this->fixture();
        DB::table('sso_sessions')->where('sid', 'hinted')->update(['expires_at' => time() - 86401]);
        $this->get('/oidc/logout?'.http_build_query(['id_token_hint' => $hint]))->assertStatus(400);
        $this->actingAs($user)->withSession(['sso.sid' => 'hinted'])->get('/oidc/logout')->assertOk();
        $transaction = session('sso.logout.transaction');
        $this->withSession(['sso.sid' => 'another-session'])->post('/oidc/logout/confirm', ['transaction' => $transaction])->assertStatus(400);
    }
}
