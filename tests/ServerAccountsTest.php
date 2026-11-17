<?php

namespace LaravelSso\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use LaravelSso\Server\Accounts;
use LaravelSso\Server\ClientUsers;
use LaravelSso\Server\Notifications\CentralPasswordReset;
use LaravelSso\Tests\Fixtures\GreenfieldUser;

final class ServerAccountsTest extends TestCase
{
    private const PASSWORD = 'Central-password-123!';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('auth.providers.users.model', GreenfieldUser::class);
        $app['config']->set('auth.passwords.users', ['provider' => 'users', 'table' => 'password_reset_tokens', 'expire' => 60, 'throttle' => 60]);
        $app['config']->set('sso-server.active_column', null);
        $app['config']->set('sso-server.registration', 'open');
        $app['config']->set('sso-server.password_broker', 'users');
        $app['config']->set('sso-client.enabled', false);
    }

    protected function defineRoutes($router): void {}

    protected function setUp(): void
    {
        parent::setUp();
        Schema::drop('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Notification::fake();
    }

    private function account(string $email = 'central@example.test'): GreenfieldUser
    {
        return app(Accounts::class)->create(['name' => 'Central person', 'email' => $email,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
    }

    private function application(bool $consent = false): Client
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Example client', ['https://app.test/sso/callback'], true);
        DB::table('sso_clients')->insert(['client_id' => $client->id, 'restrict_users' => true, 'auto_approve' => ! $consent]);

        return $client;
    }

    private function authorization(GreenfieldUser $user, Client $client): string
    {
        return '/oidc/authorize?'.http_build_query(['client_id' => $client->id, 'redirect_uri' => 'https://app.test/sso/callback',
            'response_type' => 'code', 'scope' => 'openid profile email', 'state' => 'central-assignment', 'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 64), true)), '+/', '-_'), '=')]);
    }

    private function exchange(GreenfieldUser $user, Client $client): array
    {
        $response = $this->actingAs($user, 'web')->get($this->authorization($user, $client))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return ['grant_type' => 'authorization_code', 'client_id' => $client->id, 'client_secret' => $client->plainSecret,
            'redirect_uri' => 'https://app.test/sso/callback', 'code' => $query['code'], 'code_verifier' => str_repeat('v', 64)];
    }

    private function resetNotification(string $email): CentralPasswordReset
    {
        $notification = null;
        Notification::assertSentOnDemand(CentralPasswordReset::class, function ($sent) use ($email, &$notification): bool {
            if ($sent->email !== $email) {
                return false;
            }
            $notification = $sent;

            return true;
        });

        return $notification;
    }

    private function resetInput(CentralPasswordReset $notification, string $password = 'Replacement-password-456!'): array
    {
        return ['email' => $notification->email, 'token' => $notification->token, 'password' => $password, 'password_confirmation' => $password];
    }

    public function test_registration_has_json_and_optional_forms_without_auto_login_or_app_grants(): void
    {
        $columns = Schema::getColumnListing('users');
        $this->get('/sso/auth/register')->assertOk()->assertSee('name="password_confirmation"', false);
        $this->getJson('/sso/auth/register')->assertOk()->assertJsonPath('interaction', 'register')->assertJsonStructure(['csrf_token']);
        $this->postJson('/sso/auth/register', ['name' => 'Person', 'email' => 'Person@Example.Test', 'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD, 'is_admin' => true, 'client_id' => 'untrusted-app'])->assertCreated()
            ->assertJsonPath('registered', true)->assertDontSee(self::PASSWORD);
        $user = GreenfieldUser::sole();
        $this->assertSame('person@example.test', $user->email);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNull($user->email_verified_at);
        $this->assertArrayNotHasKey('is_admin', $user->getAttributes());
        $this->assertSame(0, DB::table('sso_client_users')->count());
        $this->assertSame($columns, Schema::getColumnListing('users'));
        $this->assertGuest('web');
    }

    public function test_closed_registration_and_host_login_disable_alternative_account_endpoints(): void
    {
        config(['sso-server.registration' => 'closed']);
        $this->get('/sso/auth/register')->assertNotFound();
        $this->postJson('/sso/auth/register', [])->assertNotFound();
        Route::get('/host-login', fn () => 'Host MFA')->name('login');
        Route::getRoutes()->refreshNameLookups();
        config(['sso-server.registration' => 'open']);
        $this->postJson('/sso/auth/register', [])->assertNotFound();
        $this->postJson('/sso/auth/forgot-password', ['email' => 'person@example.test'])->assertNotFound();
        $this->postJson('/sso/auth/reset-password', [])->assertNotFound();
        $this->assertSame(0, GreenfieldUser::count());
    }

    public function test_duplicate_registration_and_password_validation_never_change_existing_accounts(): void
    {
        $user = $this->account();
        $before = $user->fresh()->getAttributes();
        foreach ([['email' => 'CENTRAL@example.test', 'password' => self::PASSWORD],
            ['email' => 'new@example.test', 'password' => 'short'],
            ['email' => 'new@example.test', 'password' => str_repeat('é', 40)]] as $input) {
            $this->postJson('/sso/auth/register', $input + ['name' => 'Person', 'password_confirmation' => $input['password']])->assertUnprocessable();
        }
        $this->assertSame(1, GreenfieldUser::count());
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_forgot_password_is_generic_throttled_hashed_and_uses_configured_issuer(): void
    {
        $user = $this->account();
        $before = $user->fresh()->getAttributes();
        $known = $this->withHeaders(['Host' => 'untrusted.test'])->postJson('/sso/auth/forgot-password', ['email' => $user->email])->assertAccepted();
        $unknown = $this->postJson('/sso/auth/forgot-password', ['email' => 'unknown@example.test'])->assertAccepted();
        $throttled = $this->postJson('/sso/auth/forgot-password', ['email' => $user->email])->assertAccepted();
        $this->assertSame($known->json(), $unknown->json());
        $this->assertSame($known->json(), $throttled->json());
        Notification::assertCount(1);
        $notification = $this->resetNotification($user->email);
        $this->assertStringStartsWith('https://identity.test/sso/auth/reset-password/', $notification->url());
        $this->assertStringNotContainsString('untrusted.test', $notification->url());
        $this->assertNotSame($notification->token, DB::table('password_reset_tokens')->value('token'));
        $this->assertTrue(Hash::check($notification->token, DB::table('password_reset_tokens')->value('token')));
        $known->assertDontSee($notification->token);
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_reset_is_single_use_changes_only_target_and_never_authenticates_automatically(): void
    {
        $target = $this->account();
        $other = $this->account('other@example.test');
        $otherBefore = $other->fresh()->getAttributes();
        $before = $target->fresh()->getAttributes();
        $this->postJson('/sso/auth/forgot-password', ['email' => $target->email])->assertAccepted();
        $notification = $this->resetNotification($target->email);
        $this->postJson('/sso/auth/reset-password', $this->resetInput($notification))->assertOk()->assertJsonPath('password_reset', true);
        $this->assertTrue(Hash::check('Replacement-password-456!', $target->fresh()->password));
        foreach (['id', 'name', 'email', 'email_verified_at', 'created_at'] as $column) {
            $this->assertSame($before[$column], $target->fresh()->getAttributes()[$column]);
        }
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
        $after = $target->fresh()->getAttributes();
        $this->postJson('/sso/auth/reset-password', $this->resetInput($notification, 'Another-password-789!'))->assertUnprocessable();
        $this->assertSame($after, $target->fresh()->getAttributes());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertGuest('web');
    }

    public function test_wrong_expired_or_replaced_token_never_changes_password(): void
    {
        $user = $this->account();
        $before = $user->fresh()->getAttributes();
        $this->postJson('/sso/auth/forgot-password', ['email' => $user->email])->assertAccepted();
        $first = $this->resetNotification($user->email);
        $input = $this->resetInput($first);
        $input['token'] = str_repeat('0', 64);
        $this->postJson('/sso/auth/reset-password', $input)->assertUnprocessable();
        $this->travel(61)->minutes();
        $this->postJson('/sso/auth/reset-password', $this->resetInput($first))->assertUnprocessable();
        $this->postJson('/sso/auth/forgot-password', ['email' => $user->email])->assertAccepted();
        $this->postJson('/sso/auth/reset-password', $this->resetInput($first))->assertUnprocessable();
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_reset_revokes_global_sessions_and_tokens_and_other_users_stay_unchanged(): void
    {
        $user = $this->account();
        $other = $this->account('other@example.test');
        $before = $other->fresh()->getAttributes();
        $client = $this->application();
        app(ClientUsers::class)->assign($client->id, (string) $user->id);
        $token = $this->postJson('/oidc/token', $this->exchange($user, $client))->assertOk()->json('access_token');
        $this->postJson('/sso/auth/forgot-password', ['email' => $user->email])->assertAccepted();
        $this->postJson('/sso/auth/reset-password', $this->resetInput($this->resetNotification($user->email)))->assertOk();
        $this->getJson('/oidc/userinfo', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        $this->assertSame(0, DB::table('sso_sessions')->where('revoked', false)->count());
        $this->assertSame(0, DB::table('oauth_access_tokens')->where('revoked', false)->count());
        $this->assertSame($before, $other->fresh()->getAttributes());
    }

    public function test_forms_support_json_headless_mode_and_all_posts_require_csrf(): void
    {
        config(['sso-server.login_ui' => false]);
        $this->get('/sso/auth/forgot-password')->assertOk()->assertJsonPath('interaction', 'forgot_password');
        $this->get('/sso/auth/reset-password/'.str_repeat('a', 64).'?email=person@example.test')->assertOk()->assertJsonPath('interaction', 'reset_password');
        $this->app['env'] = 'local';
        foreach (['register', 'forgot-password', 'reset-password'] as $path) {
            $this->postJson('/sso/auth/'.$path, [])->assertStatus(419);
        }
        $this->assertSame(0, GreenfieldUser::count());
        Notification::assertNothingSent();
    }

    public function test_operator_can_create_and_assign_an_account_with_a_hidden_password(): void
    {
        $client = $this->application();
        $this->artisan('sso:user', ['email' => 'manual@example.test', '--name' => 'Manual account', '--client' => [$client->id]])
            ->expectsQuestion('Initial central password', self::PASSWORD)->expectsQuestion('Confirm central password', self::PASSWORD)->assertSuccessful();
        $user = GreenfieldUser::sole();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertTrue(app(ClientUsers::class)->allows($client->id, (string) $user->id));
    }

    public function test_operator_mail_invitation_and_bad_assignment_rollback_preserve_existing_accounts(): void
    {
        $existing = $this->account();
        $before = $existing->fresh()->getAttributes();
        $this->assertSame(1, Artisan::call('sso:user', ['email' => 'new@example.test', '--send-reset' => true, '--client' => ['missing-app']]));
        $this->assertSame(1, GreenfieldUser::count());
        Notification::assertNothingSent();
        $client = $this->application();
        $this->assertSame(0, Artisan::call('sso:user', ['email' => 'invited@example.test', '--send-reset' => true, '--client' => [$client->id]]));
        $notification = $this->resetNotification('invited@example.test');
        $this->assertStringNotContainsString($notification->token, Artisan::output());
        $this->assertTrue(app(ClientUsers::class)->allows($client->id, (string) GreenfieldUser::where('email', 'invited@example.test')->value('id')));
        $this->assertSame($before, $existing->fresh()->getAttributes());
    }

    public function test_application_is_restricted_by_default_and_assignments_are_idempotent_without_user_updates(): void
    {
        $this->assertSame(0, Artisan::call('sso:client', ['name' => 'Example client', 'redirect' => 'https://app.test/sso/callback']));
        $id = DB::table('sso_clients')->value('client_id');
        $this->assertTrue((bool) DB::table('sso_clients')->value('restrict_users'));
        $user = $this->account();
        $before = $user->fresh()->getAttributes();
        $this->assertFalse(app(ClientUsers::class)->allows($id, (string) $user->id));
        foreach ([1, 2] as $attempt) {
            $this->assertSame(0, Artisan::call('sso:client-user', ['client' => $id, 'user' => (string) $user->id]));
        }
        $this->assertSame(1, DB::table('sso_client_users')->count());
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_unassigned_user_cannot_authorize_and_assignment_cannot_be_added_from_public_registration(): void
    {
        $user = $this->account();
        $client = $this->application();
        $response = $this->actingAs($user, 'web')->get($this->authorization($user, $client))->assertRedirect();
        $this->assertStringContainsString('error=access_denied', $response->headers->get('Location'));
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
    }

    public function test_removal_blocks_pending_consent_and_code_exchange(): void
    {
        $user = $this->account();
        $client = $this->application(true);
        app(ClientUsers::class)->assign($client->id, (string) $user->id);
        $consent = $this->actingAs($user, 'web')->getJson($this->authorization($user, $client))->assertOk();
        app(ClientUsers::class)->assign($client->id, (string) $user->id, true);
        $response = $this->postJson('/oidc/consent', ['transaction' => $consent->json('transaction'), 'approve' => '1'])->assertRedirect();
        $this->assertStringContainsString('error=access_denied', $response->headers->get('Location'));
        DB::table('sso_clients')->where('client_id', $client->id)->update(['auto_approve' => true]);
        app(ClientUsers::class)->assign($client->id, (string) $user->id);
        $exchange = $this->exchange($user, $client);
        app(ClientUsers::class)->assign($client->id, (string) $user->id, true);
        $this->postJson('/oidc/token', $exchange)->assertBadRequest();
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
    }

    public function test_removing_one_app_revokes_its_tokens_without_affecting_other_app_or_account(): void
    {
        $user = $this->account();
        $before = $user->fresh()->getAttributes();
        $one = $this->application();
        $two = $this->application();
        app(ClientUsers::class)->assign($one->id, (string) $user->id);
        app(ClientUsers::class)->assign($two->id, (string) $user->id);
        $first = $this->postJson('/oidc/token', $this->exchange($user, $one))->assertOk()->json('access_token');
        $second = $this->postJson('/oidc/token', $this->exchange($user, $two))->assertOk()->json('access_token');
        app(ClientUsers::class)->assign($one->id, (string) $user->id, true);
        $this->getJson('/oidc/userinfo', ['Authorization' => 'Bearer '.$first])->assertUnauthorized();
        $this->getJson('/oidc/session', ['Authorization' => 'Bearer '.$second])->assertOk()->assertJsonPath('active', true);
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_doctor_rejects_password_broker_from_another_provider(): void
    {
        config(['auth.passwords.users.provider' => 'locals']);
        $this->assertSame(1, Artisan::call('sso:doctor-server', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $check = array_values(array_filter($report['checks'], fn ($check) => $check['code'] === 'password_recovery'))[0];
        $this->assertSame('fail', $check['status']);
    }

    public function test_replaced_unexpired_reset_link_is_invalidated(): void
    {
        config(['auth.passwords.users.throttle' => 0]);
        $user = $this->account();
        $before = $user->fresh()->getAttributes();
        $this->postJson('/sso/auth/forgot-password', ['email' => $user->email])->assertAccepted();
        $first = $this->resetNotification($user->email);
        $this->postJson('/sso/auth/forgot-password', ['email' => $user->email])->assertAccepted();
        $this->postJson('/sso/auth/reset-password', $this->resetInput($first))->assertUnprocessable();
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_reset_does_not_close_a_different_users_browser_session(): void
    {
        $target = $this->account();
        $other = $this->account('other@example.test');
        $before = $other->fresh()->getAttributes();
        $this->actingAs($other, 'web')->postJson('/sso/auth/forgot-password', ['email' => $target->email])->assertAccepted();
        $this->postJson('/sso/auth/reset-password', $this->resetInput($this->resetNotification($target->email)))->assertOk();
        $this->assertAuthenticatedAs($other, 'web');
        $this->assertSame($before, $other->fresh()->getAttributes());
    }
}
