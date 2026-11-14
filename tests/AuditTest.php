<?php

namespace LaravelSso\Tests;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use LaravelSso\Client\EloquentProvisioner;
use LaravelSso\Contracts\AuditEvent;
use LaravelSso\Contracts\Identity;
use LaravelSso\Server\Audit;
use LaravelSso\Server\DeviceSessions;
use LaravelSso\Server\GlobalSessions;
use LaravelSso\Server\Http\ProtocolController;
use LaravelSso\Server\LogoutDeliveries;
use LaravelSso\Server\SigningKeys;
use LaravelSso\Tests\Fixtures\CentralUser;
use Psr\Log\LoggerInterface;

final class AuditTest extends TestCase
{
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::listen(AuditEvent::class, function (AuditEvent $event): void {
            $this->events[] = $event;
        });
        Route::middleware('web')->post('/audit-login', function (Request $request) {
            return response()->json(['ok' => Auth::guard('web')->attempt($request->only('email', 'password'))]);
        });
    }

    private function names(): array
    {
        return array_column($this->events, 'name');
    }

    private function user(): CentralUser
    {
        return CentralUser::create(['name' => 'Private name', 'email' => 'private@example.test', 'password' => bcrypt('private-password')]);
    }

    public function test_events_wait_for_outer_commit_and_disappear_on_rollback(): void
    {
        $user = $this->user();
        $request = Request::create('/');
        $request->setLaravelSession(session()->driver());
        DB::beginTransaction();
        $device = app(DeviceSessions::class)->register($request, $user, true);
        $this->assertSame([], $this->events);
        DB::rollBack();
        $this->assertSame([], $this->events);
        $this->assertFalse(DB::table('sso_devices')->where('id', $device->id)->exists());
        DB::beginTransaction();
        app(DeviceSessions::class)->register($request, $user, true);
        $session = app(GlobalSessions::class)->establish($request, $user);
        $this->assertSame([], $this->events);
        DB::commit();
        $this->assertSame(['device.registered', 'session.created'], $this->names());
        app(DeviceSessions::class)->revoke($request->session()->get('sso.device_id'));
        app(DeviceSessions::class)->revoke($request->session()->get('sso.device_id'));
        $this->assertCount(4, $this->events);
        $this->assertContains('device.revoked', $this->names());
        $this->assertContains('session.revoked', $this->names());
        $this->assertNotSame($session->sid, $this->events[1]->references['sid']);
    }

    public function test_native_login_failure_and_success_never_include_credentials_or_profile(): void
    {
        $user = $this->user();
        $this->post('/audit-login', ['email' => $user->email, 'password' => 'wrong-password'])->assertOk();
        $this->post('/audit-login', ['email' => $user->email, 'password' => 'private-password', 'remember' => true])->assertOk();
        $this->assertSame(['auth.failed', 'device.registered', 'auth.login'], $this->names());
        $json = json_encode($this->events, JSON_THROW_ON_ERROR);
        foreach (['private@example.test', 'private-password', 'wrong-password', 'Private name', $user->password, 'password', 'email', 'user_agent'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertTrue($this->events[1]->context['remembered']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $this->events[2]->references['subject']);
    }

    public function test_remember_restore_and_verified_replay_are_distinct_from_guessed_secrets(): void
    {
        $user = $this->user();
        $response = $this->post('/audit-login', ['email' => $user->email, 'password' => 'private-password', 'remember' => true])->assertOk();
        $cookie = $response->getCookie(app(DeviceSessions::class)->cookieName())->getValue();
        $this->resetBrowser();
        $this->withCookie(app(DeviceSessions::class)->cookieName(), $cookie)->getJson('/sso/devices')->assertOk();
        $this->assertContains('remember.restored', $this->names());
        $this->resetBrowser();
        $guessed = explode('.', $cookie)[0].'.'.str_repeat('0', 64);
        $this->withCookie(app(DeviceSessions::class)->cookieName(), $guessed)->getJson('/sso/devices')->assertUnauthorized();
        $this->assertNotContains('remember.replayed', $this->names());
        $this->resetBrowser();
        $this->withCookie(app(DeviceSessions::class)->cookieName(), $cookie)->getJson('/sso/devices')->assertUnauthorized();
        $this->assertContains('remember.replayed', $this->names());
        $this->assertStringNotContainsString($cookie, json_encode($this->events));
    }

    private function resetBrowser(): void
    {
        session()->invalidate();
        Auth::forgetGuards();
        $this->defaultCookies = [];
        foreach (Cookie::getQueuedCookies() as $cookie) {
            Cookie::unqueue($cookie->getName(), $cookie->getPath());
        }
        $this->withCredentials();
    }

    public function test_provisioning_audit_is_transactional_and_has_no_model_or_identity_attributes(): void
    {
        config(['sso-client.provision' => 'automatic']);
        $identity = new Identity('https://identity.test', 'private-subject', 'private-sid', time(), ['email' => 'private@example.test']);
        DB::beginTransaction();
        app(EloquentProvisioner::class)->provision($identity);
        DB::rollBack();
        $this->assertSame([], $this->events);
        app(EloquentProvisioner::class)->provision($identity);
        app(EloquentProvisioner::class)->provision($identity);
        $this->assertSame(['identity.provisioned', 'identity.provisioned'], $this->names());
        $this->assertTrue($this->events[0]->context['created']);
        $this->assertFalse($this->events[1]->context['created']);
        $this->assertStringNotContainsString('private', json_encode($this->events));
        $this->assertNotSame(AuditEvent::make('server', 'auth.login', app('encrypter')->getKey(), ['subject' => 'private-subject'])->references['subject'], $this->events[0]->references['subject']);
    }

    public function test_invalid_callback_and_unverified_logout_do_not_log_attacker_input(): void
    {
        Http::preventStrayRequests();
        $this->get('/sso/callback?state=private-state&code=private-code')->assertStatus(400);
        $this->postJson('/sso/backchannel-logout', ['logout_token' => []])->assertUnprocessable();
        $this->assertSame(['login.rejected', 'logout.rejected'], $this->names());
        $this->assertStringNotContainsString('private', json_encode($this->events));
        Http::assertNothingSent();
    }

    public function test_backchannel_duplicate_has_its_own_event_and_does_not_repeat_revocation(): void
    {
        Http::fake([
            'https://identity.test/.well-known/openid-configuration' => Http::response(app(ProtocolController::class)->discovery()->getData(true)),
            'https://identity.test/oidc/jwks' => Http::response(app(SigningKeys::class)->jwks()),
        ]);
        DB::table('sso_local_sessions')->insert(['session_hash' => hash('sha256', 'local'), 'issuer' => 'https://identity.test', 'subject' => 'private-subject', 'sid' => 'private-sid', 'revoked' => false]);
        $token = app(SigningKeys::class)->sign(['iss' => 'https://identity.test', 'aud' => config('sso-client.client_id'),
            'sub' => 'private-subject', 'sid' => 'private-sid', 'iat' => time(), 'exp' => time() + 120, 'jti' => 'private-jti',
            'events' => ['http://schemas.openid.net/event/backchannel-logout' => (object) []]]);
        $this->post('/sso/backchannel-logout', ['logout_token' => $token])->assertOk();
        $this->post('/sso/backchannel-logout', ['logout_token' => $token])->assertOk();
        $this->assertSame(['logout.received', 'logout.duplicate'], $this->names());
        $this->assertSame(1, $this->events[0]->context['count']);
        $this->assertStringNotContainsString('private', json_encode($this->events));
        $this->assertStringNotContainsString($token, json_encode($this->events));
    }

    public function test_delivery_retry_and_success_are_recorded_only_for_the_lease_owner(): void
    {
        DB::table('sso_sessions')->insert(['sid' => 'private-sid', 'subject' => 'private-subject', 'auth_time' => time(), 'last_seen' => time(), 'expires_at' => time() + 300, 'revoked' => true]);
        DB::table('sso_clients')->insert(['client_id' => config('sso-client.client_id'), 'backchannel_logout_uri' => 'https://one.test/logout']);
        DB::table('sso_logout_deliveries')->insert(['id' => 'private-delivery', 'sid' => 'private-sid', 'client_id' => config('sso-client.client_id'), 'next_attempt' => time()]);
        config(['sso-server.backchannel_allowed_urls' => ['https://one.test/logout']]);
        Http::fakeSequence()->push([], 503)->push([], 200);
        app(LogoutDeliveries::class)->deliverOne();
        $this->assertFalse(app(LogoutDeliveries::class)->deliverOne());
        DB::table('sso_logout_deliveries')->update(['next_attempt' => time()]);
        app(LogoutDeliveries::class)->deliverOne();
        $this->assertSame(['logout.retry', 'logout.delivered'], $this->names());
        $this->assertSame(2, $this->events[1]->context['attempt']);
    }

    public function test_log_sink_is_opt_in_and_receives_only_the_sanitized_envelope(): void
    {
        Log::spy();
        app(Audit::class)->record('auth.failed');
        Log::shouldNotHaveReceived('channel');
        config(['sso-server.audit_log' => true, 'sso-server.audit_channel' => 'security']);
        $logger = \Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->once()->with('security')->andReturn($logger);
        $logger->shouldReceive('info')->once()->with('sso.audit', \Mockery::on(fn ($context) => $context['version'] === 1 && $context['name'] === 'auth.failed' && $context['references'] === []));
        app(Audit::class)->record('auth.failed');
        $this->assertCount(2, $this->events);
    }

    public function test_envelope_rejects_unknown_metadata_and_event_names(): void
    {
        foreach ([['references' => ['email' => 'private@example.test']], ['context' => ['password' => 'secret']], ['name' => 'private-token']] as $invalid) {
            try {
                AuditEvent::make('server', $invalid['name'] ?? 'auth.login', 'key', $invalid['references'] ?? [], $invalid['context'] ?? []);
                $this->fail('Unsafe audit payload accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_device_administration_and_password_reset_are_audited_without_labels(): void
    {
        $user = $this->user();
        $this->post('/audit-login', ['email' => $user->email, 'password' => 'private-password'])->assertOk();
        $device = DB::table('sso_devices')->first();
        session()->put('sso.password_confirmed_at', time() - 301);
        $this->postJson('/sso/devices/'.$device->id.'/label', ['label' => 'private-label'])->assertForbidden();
        $this->postJson('/sso/devices/confirm', ['password' => 'wrong-password'])->assertStatus(422);
        $this->postJson('/sso/devices/confirm', ['password' => 'private-password'])->assertOk();
        $this->postJson('/sso/devices/'.$device->id.'/label', ['label' => 'private-label'])->assertOk();
        event(new PasswordReset($user));
        foreach (['password.required', 'password.rejected', 'password.confirmed', 'device.renamed', 'auth.password_reset', 'device.revoked'] as $name) {
            $this->assertContains($name, $this->names());
        }
        $this->assertStringNotContainsString('private-', json_encode($this->events));
    }

    public function test_confidential_client_registration_audit_does_not_copy_the_one_time_secret(): void
    {
        $this->assertSame(0, Artisan::call('sso:client', ['name' => 'Private application', 'redirect' => 'https://one.test/callback']));
        preg_match('/SSO_CLIENT_SECRET=([^\r\n]+)/', Artisan::output(), $matches);
        $this->assertNotEmpty($matches[1]);
        $this->assertSame(['client.registered'], $this->names());
        $this->assertStringNotContainsString($matches[1], json_encode($this->events));
        $this->assertStringNotContainsString('Private application', json_encode($this->events));
    }
}
