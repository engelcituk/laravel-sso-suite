<?php

namespace LaravelSso\Tests;

use Firebase\JWT\JWT;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\ClientRepository;
use LaravelSso\Server\DeviceSessions;
use LaravelSso\Server\GlobalSessions;
use LaravelSso\Server\PruneState;
use LaravelSso\Tests\Fixtures\CentralUser;
use PHPUnit\Framework\Attributes\DataProvider;

final class RememberDeviceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('web')->post('/central-login', function (Request $request) {
            if (! Auth::guard('web')->attempt(['email' => $request->input('email'), 'password' => $request->input('password'), 'is_active' => true], false)) {
                return response()->json([], 401);
            }
            $request->session()->regenerate();

            return response()->json(['authenticated' => true]);
        });
        Route::middleware('web')->post('/central-logout', function (Request $request) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return response()->noContent();
        });
    }

    private function login(bool $remember = true): array
    {
        $user = CentralUser::create(['name' => 'Device owner', 'email' => 'owner@example.test', 'password' => bcrypt('correct-password')]);
        $response = $this->post('/central-login', ['email' => $user->email, 'password' => 'correct-password', 'remember' => $remember ? '1' : '0'])->assertOk();
        $device = DB::table('sso_devices')->first();
        $cookie = $remember ? $response->getCookie(app(DeviceSessions::class)->cookieName()) : null;

        return [$user, $device, $cookie];
    }

    private function browser(?string $cookie = null): void
    {
        session()->invalidate();
        Auth::forgetGuards();
        $this->defaultCookies = [];
        foreach (Cookie::getQueuedCookies() as $queued) {
            Cookie::unqueue($queued->getName(), $queued->getPath());
        }
        if ($cookie !== null) {
            $this->withCookie(app(DeviceSessions::class)->cookieName(), $cookie);
        }
        $this->withCredentials();
    }

    private function otherDevice(CentralUser $user): object
    {
        $id = bin2hex(random_bytes(32));
        DB::table('sso_devices')->insert(['id' => $id, 'subject' => (string) $user->id, 'label' => 'Other browser', 'user_agent' => 'Other agent',
            'credential_hash' => app(DeviceSessions::class)->credentialHash($user), 'auth_time' => time() - 100,
            'created_at' => time() - 100, 'last_seen' => time(), 'expires_at' => time() + 3600, 'remember_until' => time() + 3600]);

        return DB::table('sso_devices')->where('id', $id)->first();
    }

    public function test_remember_cookie_is_opt_in_host_only_secure_and_only_hashes_are_stored(): void
    {
        Cookie::setDefaultPathAndDomain('/', '.example.test', false, 'none');
        [, $device, $cookie] = $this->login();
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertStringStartsWith('__Host-', $cookie->getName());
        [$id, $secret] = explode('.', $cookie->getValue());
        $token = DB::table('sso_remember_tokens')->where('id', $id)->first();
        $this->assertSame(hash('sha256', $secret), $token->token_hash);
        $this->assertStringNotContainsString($secret, json_encode($token));
        $this->assertNull($device->ip_address);
        $this->assertSame($device->expires_at, $device->remember_until);
        $this->assertSame(1, DB::table('sso_remember_tokens')->count());
    }

    public function test_without_opt_in_no_persistent_token_is_created(): void
    {
        [, $device] = $this->login(false);
        $this->assertNull($device->remember_until);
        $this->assertSame(0, DB::table('sso_remember_tokens')->count());
        $this->browser();
        $this->getJson('/sso/devices')->assertUnauthorized();
    }

    public function test_restore_rotates_token_session_and_preserves_original_authentication_time(): void
    {
        [$user, $device, $cookie] = $this->login();
        $originalAuthTime = time() - 600;
        DB::table('sso_devices')->where('id', $device->id)->update(['auth_time' => $originalAuthTime]);
        $before = session()->getId();
        $this->browser($cookie->getValue());
        $response = $this->get('/sso/devices')->assertOk();
        $next = $response->getCookie($cookie->getName());
        $this->assertNotSame($cookie->getValue(), $next->getValue());
        $this->assertNotSame($before, session()->getId());
        $this->assertSame((string) $user->id, (string) Auth::guard('web')->id());
        $this->assertSame($device->id, session('sso.device_id'));
        $this->assertSame($originalAuthTime, session('sso.auth_time'));
        $this->assertTrue(session('sso.remembered'));
        $this->assertNull(session('sso.login_epoch'));
        $this->assertNull(session('sso.password_confirmed_at'));
        $this->assertSame(2, DB::table('sso_remember_tokens')->count());
        $this->assertSame(1, DB::table('sso_remember_tokens')->whereNotNull('used_at')->count());
        $this->assertSame((int) $device->remember_until, (int) DB::table('sso_remember_tokens')->max('expires_at'));
        $this->assertNotNull(app(GlobalSessions::class)->valid(session('sso.sid')));
    }

    public function test_verified_replay_revokes_the_rotated_credential_and_all_device_sessions(): void
    {
        [, $device, $cookie] = $this->login();
        $this->browser($cookie->getValue());
        $response = $this->get('/sso/devices')->assertOk();
        $next = $response->getCookie($cookie->getName())->getValue();
        $sid = session('sso.sid');
        $this->browser($cookie->getValue());
        $this->getJson('/sso/devices')->assertUnauthorized();
        $this->assertNotNull(DB::table('sso_devices')->where('id', $device->id)->value('revoked_at'));
        $this->assertTrue((bool) DB::table('sso_sessions')->where('sid', $sid)->value('revoked'));
        $this->browser($next);
        $this->getJson('/sso/devices')->assertUnauthorized();
        $this->assertNull(app(GlobalSessions::class)->valid($sid));
    }

    public function test_a_guessed_validator_cannot_revoke_a_valid_device(): void
    {
        [, $device, $cookie] = $this->login();
        [$selector] = explode('.', $cookie->getValue());
        $this->browser($selector.'.'.str_repeat('0', 64));
        $this->getJson('/sso/devices')->assertUnauthorized();
        $this->assertNull(DB::table('sso_devices')->where('id', $device->id)->value('revoked_at'));
        $this->assertSame(0, DB::table('sso_remember_tokens')->whereNotNull('used_at')->count());
        $this->browser($cookie->getValue());
        $this->getJson('/sso/devices')->assertOk();
    }

    public static function invalidCredentials(): array
    {
        return ['expired token' => ['token'], 'expired device' => ['device'], 'disabled feature' => ['disabled'],
            'inactive identity' => ['inactive'], 'changed password' => ['password']];
    }

    #[DataProvider('invalidCredentials')]
    public function test_expired_or_invalidated_remember_credentials_cannot_restore(string $reason): void
    {
        [$user, $device, $cookie] = $this->login();
        match ($reason) {
            'token' => DB::table('sso_remember_tokens')->update(['expires_at' => time() - 1]),
            'device' => DB::table('sso_devices')->update(['expires_at' => time() - 1]),
            'disabled' => config(['sso-server.remember_enabled' => false]),
            'inactive' => $user->forceFill(['is_active' => false])->save(),
            'password' => $user->forceFill(['password' => bcrypt('new-password')])->save(),
        };
        $this->browser($cookie->getValue());
        $this->getJson('/sso/devices')->assertUnauthorized();
        $this->assertSame(1, DB::table('sso_remember_tokens')->count());
    }

    public function test_restored_authentication_requires_password_confirmation_for_device_changes(): void
    {
        [, $device, $cookie] = $this->login();
        $this->browser($cookie->getValue());
        $this->get('/sso/devices')->assertOk()->assertSee('Confirma que eres tú');
        // Keep the newly rotated cookie, not the consumed credential, on later requests.
        $this->defaultCookies = [];
        $this->postJson('/sso/devices/'.$device->id.'/label', ['label' => 'Work browser'])->assertForbidden();
        $this->postJson('/sso/devices/confirm', ['password' => 'wrong'])->assertStatus(422);
        $this->postJson('/sso/devices/confirm', ['password' => 'correct-password'])->assertOk();
        $this->postJson('/sso/devices/'.$device->id.'/label', ['label' => 'Work browser'])->assertOk();
        $this->assertSame('Work browser', DB::table('sso_devices')->where('id', $device->id)->value('label'));
        session()->put('sso.password_confirmed_at', time() - 301);
        $this->postJson('/sso/devices/revoke-others')->assertForbidden();
    }

    public function test_device_list_and_mutations_are_scoped_to_the_authenticated_subject(): void
    {
        [, $own] = $this->login(false);
        $other = CentralUser::create(['name' => 'Other owner', 'email' => 'other@example.test', 'password' => bcrypt('other-password')]);
        $foreign = $this->otherDevice($other);
        $this->getJson('/sso/devices')->assertOk()->assertJsonCount(1, 'devices')->assertJsonPath('devices.0.id', $own->id)
            ->assertJsonMissingPath('devices.0.credential_hash')->assertJsonMissingPath('devices.0.token_hash');
        $this->postJson('/sso/devices/'.$foreign->id.'/revoke')->assertNotFound();
        $this->postJson('/sso/devices/'.$foreign->id.'/label', ['label' => 'Intrusion'])->assertNotFound();
        $this->assertNull(DB::table('sso_devices')->where('id', $foreign->id)->value('revoked_at'));
        $this->assertSame('Other browser', DB::table('sso_devices')->where('id', $foreign->id)->value('label'));
    }

    public function test_revoke_other_devices_preserves_current_and_other_subjects_and_queues_backchannel(): void
    {
        [$user, $current] = $this->login(false);
        $other = $this->otherDevice($user);
        $client = '00000000-0000-4000-8000-000000000001';
        DB::table('sso_clients')->insert(['client_id' => $client, 'backchannel_logout_uri' => 'https://one.test/logout']);
        DB::table('sso_sessions')->insert(['sid' => 'other-sid', 'device_id' => $other->id, 'subject' => (string) $user->id,
            'auth_time' => time(), 'last_seen' => time(), 'expires_at' => time() + 1000, 'revoked' => false]);
        DB::table('oauth_access_tokens')->insert(['id' => str_repeat('a', 80), 'user_id' => $user->id, 'client_id' => $client,
            'scopes' => '[]', 'revoked' => false, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 300)]);
        DB::table('sso_token_sessions')->insert(['token_id' => str_repeat('a', 80), 'sid' => 'other-sid', 'client_id' => $client]);
        $this->postJson('/sso/devices/revoke-others')->assertOk();
        $this->assertNull(DB::table('sso_devices')->where('id', $current->id)->value('revoked_at'));
        $this->assertNotNull(DB::table('sso_devices')->where('id', $other->id)->value('revoked_at'));
        $this->assertTrue((bool) DB::table('oauth_access_tokens')->value('revoked'));
        $this->assertSame(1, DB::table('sso_logout_deliveries')->where('sid', 'other-sid')->count());
        $this->assertAuthenticated('web');
    }

    public function test_revoking_current_device_clears_native_session_and_prevents_cookie_resurrection(): void
    {
        [, $device, $cookie] = $this->login();
        $this->postJson('/sso/devices/'.$device->id.'/revoke')->assertOk()->assertJsonPath('signed_out', true);
        $this->assertGuest('web');
        $this->browser($cookie->getValue());
        $this->getJson('/sso/devices')->assertUnauthorized();
    }

    public function test_native_logout_and_password_reset_revoke_persistent_access(): void
    {
        [$user, $device, $cookie] = $this->login();
        $other = $this->otherDevice($user);
        event(new PasswordReset($user));
        $this->assertSame(2, DB::table('sso_devices')->whereNotNull('revoked_at')->count());
        $this->browser($cookie->getValue());
        $this->getJson('/sso/devices')->assertUnauthorized();
    }

    public function test_native_logout_does_not_leave_a_remember_credential_usable(): void
    {
        [, $device, $cookie] = $this->login();
        $this->post('/central-logout')->assertNoContent();
        $this->assertNotNull(DB::table('sso_devices')->where('id', $device->id)->value('revoked_at'));
        $this->browser($cookie->getValue());
        $this->getJson('/sso/devices')->assertUnauthorized();
    }

    public function test_device_labels_and_user_agents_are_escaped_in_the_html_view(): void
    {
        [, $device] = $this->login(false);
        $this->postJson('/sso/devices/'.$device->id.'/label', ['label' => '<script>alert(1)</script>'])->assertOk();
        DB::table('sso_devices')->where('id', $device->id)->update(['user_agent' => '<img src=x onerror=alert(1)>']);
        $this->get('/sso/devices')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<img src=x', false)->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('aria-label="Cerrar esta sesión de &lt;script&gt;alert(1)&lt;/script&gt;"', false);
    }

    public function test_device_subject_queries_are_case_sensitive(): void
    {
        [, $device] = $this->login(false);
        foreach (['Account', 'account'] as $subject) {
            DB::table('sso_devices')->insert(array_replace((array) $device, ['id' => bin2hex(random_bytes(32)), 'subject' => $subject]));
        }
        $this->assertSame(1, DB::table('sso_devices')->where('subject', 'Account')->count());
        $this->assertSame(1, DB::table('sso_devices')->where('subject', 'account')->count());
    }

    public function test_device_actions_enforce_csrf_and_guest_access_is_denied(): void
    {
        $this->get('/sso/devices')->assertRedirect('/login');
        $this->getJson('/sso/devices')->assertUnauthorized();
        $this->app->instance('env', 'local');
        $this->post('/sso/devices/revoke-others')->assertStatus(419);
        $this->assertSame(0, DB::table('sso_devices')->count());
    }

    public static function freshAuthenticationRequests(): array
    {
        return ['prompt login' => [['prompt' => 'login'], false], 'max age zero' => [['max_age' => '0'], false],
            'old password authentication' => [['max_age' => '5'], false],
            'silent request requiring new authentication' => [['prompt' => 'none', 'max_age' => '5'], true]];
    }

    #[DataProvider('freshAuthenticationRequests')]
    public function test_remembered_login_never_satisfies_required_fresh_authentication(array $parameters, bool $silent): void
    {
        [, $device, $cookie] = $this->login();
        DB::table('sso_devices')->where('id', $device->id)->update(['auth_time' => time() - 600]);
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Fresh login test', ['https://one.test/callback'], false);
        DB::table('sso_clients')->insert(['client_id' => $client->id, 'auto_approve' => true]);
        $this->browser($cookie->getValue());
        $response = $this->get('/oidc/authorize?'.http_build_query($parameters + [
            'client_id' => $client->id, 'response_type' => 'code', 'redirect_uri' => 'https://one.test/callback',
            'scope' => 'openid', 'code_challenge_method' => 'S256',
            'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', str_repeat('v', 64), true)),
        ]))->assertRedirect();
        if ($silent) {
            $this->assertStringContainsString('error=login_required', $response->headers->get('Location'));
        } else {
            $response->assertRedirect('/login');
            $this->assertGuest('web');
        }
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
    }

    public function test_remote_revocation_invalidates_an_existing_browser_session_without_restoring_it(): void
    {
        [$user, $device, $cookie] = $this->login();
        $session = app(GlobalSessions::class)->establish(request(), $user);
        app(DeviceSessions::class)->revoke($device->id);
        $this->withCookie($cookie->getName(), $cookie->getValue())->get('/sso/devices')->assertRedirect('/login');
        $this->assertGuest('web');
        $this->assertSame(1, DB::table('sso_sessions')->count());
        $this->assertNull(app(GlobalSessions::class)->valid($session->sid));
    }

    public function test_legacy_native_recaller_cannot_bypass_the_per_device_policy(): void
    {
        $user = CentralUser::create(['name' => 'Legacy user', 'email' => 'legacy@example.test',
            'password' => bcrypt('password'), 'remember_token' => str_repeat('a', 60)]);
        $this->browser();
        $guard = Auth::guard('web');
        $recaller = $user->id.'|'.$user->remember_token.'|'.$guard->hashPasswordForCookie($user->getAuthPassword());
        $this->withCookie($guard->getRecallerName(), $recaller)->getJson('/sso/devices')->assertUnauthorized();
        $this->assertGuest('web');
        $this->assertSame(0, DB::table('sso_devices')->count());
    }

    public function test_an_untracked_native_session_cannot_survive_a_password_change(): void
    {
        [$user] = $this->login(false);
        session()->forget('sso.device_id');
        $user->forceFill(['password' => bcrypt('changed-password')])->save();
        Auth::guard('web')->setUser($user);
        $this->getJson('/sso/devices')->assertUnauthorized();
        $this->assertGuest('web');
        $this->assertSame(1, DB::table('sso_devices')->count());
    }

    public function test_native_explicit_remember_login_uses_the_per_device_cookie(): void
    {
        $user = CentralUser::create(['name' => 'Native user', 'email' => 'native@example.test', 'password' => bcrypt('password')]);
        Route::middleware('web')->post('/native-remember', function () use ($user) {
            abort_unless(Auth::guard('web')->attempt(['email' => $user->email, 'password' => 'password'], true), 401);

            return response()->noContent();
        });
        $response = $this->post('/native-remember')->assertNoContent();
        $response->assertCookieExpired(Auth::guard('web')->getRecallerName());
        $this->assertNotNull($response->getCookie(app(DeviceSessions::class)->cookieName()));
        $this->assertSame(1, DB::table('sso_remember_tokens')->count());
    }

    public function test_password_confirmation_is_rate_limited_and_revoke_all_signs_out_current_device(): void
    {
        $this->login(false);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/sso/devices/confirm', ['password' => 'wrong'])->assertStatus(422);
        }
        $this->postJson('/sso/devices/confirm', ['password' => 'correct-password'])->assertStatus(429);
        $this->postJson('/sso/devices/revoke-all')->assertOk()->assertJsonPath('signed_out', true);
        $this->assertGuest('web');
    }

    public function test_pruning_keeps_live_and_consumed_tokens_until_their_family_expires(): void
    {
        [, $device, $cookie] = $this->login();
        $this->browser($cookie->getValue());
        $this->get('/sso/devices')->assertOk();
        $this->assertSame(0, array_sum(app(PruneState::class)->prune()));
        $this->assertSame(2, DB::table('sso_remember_tokens')->count());
        $this->assertSame(1, DB::table('sso_remember_tokens')->whereNotNull('used_at')->count());
        DB::table('sso_sessions')->where('device_id', $device->id)->delete();
        DB::table('sso_devices')->where('id', $device->id)->update(['expires_at' => time() - 200000]);
        DB::table('sso_remember_tokens')->where('device_id', $device->id)->update(['expires_at' => time() - 200000]);
        $counts = app(PruneState::class)->prune();
        $this->assertSame(2, $counts['remember_tokens']);
        $this->assertSame(1, $counts['devices']);
        $this->assertSame(0, DB::table('sso_devices')->count());
    }
}
