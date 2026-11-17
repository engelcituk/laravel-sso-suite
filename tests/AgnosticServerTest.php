<?php

namespace LaravelSso\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\ClientRepository;
use LaravelSso\Server\GlobalSessions;
use LaravelSso\Server\Login;
use LaravelSso\Tests\Fixtures\LocalUser;

final class AgnosticServerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('auth.providers.users.model', LocalUser::class);
        $app['config']->set('sso-server.active_column', null);
        $app['config']->set('sso-client.enabled', false);
    }

    protected function defineRoutes($router): void
    {
        // A clean Laravel host: no login route, no starter kit, no is_active column.
        $router->middleware(['web', 'auth'])->get('/host-resource', fn () => 'host');
    }

    private function user(): LocalUser
    {
        return LocalUser::create(['name' => 'Existing account', 'email' => 'native@example.test',
            'password' => Hash::make('existing-password', ['rounds' => 5]), 'remember_token' => str_repeat('n', 60)]);
    }

    public function test_server_registers_its_operational_jobs_without_editing_host_console_routes(): void
    {
        $events = app(Schedule::class)->events();
        $this->assertCount(1, array_filter($events, fn ($event) => str_contains($event->command ?? '', 'sso:deliver-logouts')));
        $this->assertCount(1, array_filter($events, fn ($event) => str_contains($event->command ?? '', 'sso:prune-server')));
    }

    public function test_clean_user_model_authenticates_through_json_without_rehash_or_profile_changes(): void
    {
        $user = $this->user();
        $before = $user->fresh()->getAttributes();
        $columns = Schema::getColumnListing('users');
        $this->assertFalse(Schema::hasColumn('users', 'is_active'));
        $this->getJson('/sso/auth/session')->assertOk()->assertJsonPath('authenticated', false)->assertJsonStructure(['csrf_token']);
        $this->withSession(['url.intended' => 'https://identity.test/oidc/authorize?state=own'])
            ->postJson('/sso/auth/login', ['email' => $user->email, 'password' => 'existing-password', 'remember' => true])
            ->assertOk()->assertJsonPath('authenticated', true)->assertJsonPath('redirect_to', 'https://identity.test/oidc/authorize?state=own');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame(1, DB::table('sso_devices')->count());
        $this->assertSame(1, DB::table('sso_remember_tokens')->count());
        $this->postJson('/sso/auth/logout')->assertOk()->assertJsonPath('signed_out', true);
        $this->assertGuest('web');
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame($columns, Schema::getColumnListing('users'));
    }

    public function test_default_form_is_optional_and_host_login_has_priority_without_route_replacement(): void
    {
        $this->assertSame(route('sso-server.auth.login'), app(Login::class)->url());
        $this->get('/sso/auth/login')->assertOk()->assertSee('name="password"', false)->assertSee('name="_token"', false);
        config(['sso-server.authentication' => 'host']);
        try {
            app(Login::class)->url();
            $this->fail('Missing host login must not redirect to itself.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Host authentication requires', $error->getMessage());
        }
        config(['sso-server.authentication' => 'auto']);
        Route::middleware('web')->get('/my-login', fn () => 'Existing host frontend')->name('login');
        Route::getRoutes()->refreshNameLookups();
        $this->assertSame(route('login'), app(Login::class)->url());
        $this->get('/my-login')->assertSee('Existing host frontend');
        config(['sso-server.login_url' => '/custom-react-login', 'sso-server.login_ui' => false]);
        $this->assertSame('/custom-react-login', app(Login::class)->url());
        $this->get('/sso/auth/login')->assertOk()->assertJsonPath('authenticated', false);
    }

    public function test_wrong_credentials_and_configured_missing_or_inactive_column_fail_closed(): void
    {
        $user = $this->user();
        $before = $user->fresh()->getAttributes();
        $this->postJson('/sso/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        config(['sso-server.active_column' => 'missing_active_flag']);
        $this->assertNull(app(GlobalSessions::class)->activeUser((string) $user->id));
        $this->postJson('/sso/auth/login', ['email' => $user->email, 'password' => 'existing-password'])->assertUnprocessable();
        $this->assertGuest('web');
        $this->assertSame(0, DB::table('sso_devices')->count());
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_existing_host_authentication_cannot_be_bypassed_by_optional_password_backend(): void
    {
        $user = $this->user();
        Route::get('/mfa-login', fn () => 'Host MFA flow')->name('login');
        Route::getRoutes()->refreshNameLookups();
        $this->getJson('/sso/auth/session')->assertJsonPath('password_login_available', false);
        $this->postJson('/sso/auth/login', ['email' => $user->email, 'password' => 'existing-password'])->assertNotFound();
        $this->assertGuest('web');
        $this->get('/sso/auth/login')->assertRedirect(route('login'));
        config(['sso-server.authentication' => 'package']);
        $this->assertSame(route('sso-server.auth.login'), app(Login::class)->url());
        $this->postJson('/sso/auth/login', ['email' => $user->email, 'password' => 'existing-password'])->assertOk();
    }

    public function test_central_authentication_requires_csrf_and_preserves_users_on_rejection(): void
    {
        $user = $this->user();
        $before = $user->fresh()->getAttributes();
        $this->app['env'] = 'local';
        $this->postJson('/sso/auth/login', ['email' => $user->email, 'password' => 'existing-password'])->assertStatus(419);
        $this->assertGuest('web');
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_login_never_follows_untrusted_intended_redirect_or_reveals_password_input(): void
    {
        $user = $this->user();
        $this->withSession(['url.intended' => 'https://attacker.invalid/steal'])
            ->postJson('/sso/auth/login', ['email' => $user->email, 'password' => 'existing-password'])
            ->assertOk()->assertJsonPath('redirect_to', '/')->assertDontSee('existing-password');
        foreach (['//attacker.invalid/', '/\\attacker.invalid/', "\n/unsafe"] as $bad) {
            $this->assertSame('/', app(Login::class)->destination($bad));
        }
    }

    public function test_oidc_authorization_on_clean_host_uses_fallback_login_without_named_login_route(): void
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Clean RP', ['https://one.test/sso/callback'], true);
        DB::table('sso_clients')->insert(['client_id' => $client->id, 'auto_approve' => true]);
        $this->get('/oidc/authorize?'.http_build_query(['client_id' => $client->id, 'redirect_uri' => 'https://one.test/sso/callback',
            'response_type' => 'code', 'scope' => 'openid', 'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 64), true)), '+/', '-_'), '=')]))
            ->assertRedirect(route('sso-server.auth.login'));
    }

    public function test_existing_boolean_policy_and_custom_username_work_without_schema_mutation(): void
    {
        $user = $this->user();
        config(['sso-server.login_field' => 'name']);
        $this->postJson('/sso/auth/login', ['name' => $user->name, 'password' => 'existing-password'])->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_consent_and_logout_interactions_have_json_contracts_without_frontend_dependencies(): void
    {
        $user = $this->user();
        $before = $user->fresh()->getAttributes();
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Independent frontend', ['https://one.test/sso/callback'], true);
        DB::table('sso_clients')->insert(['client_id' => $client->id, 'auto_approve' => false]);
        $query = ['client_id' => $client->id, 'redirect_uri' => 'https://one.test/sso/callback', 'response_type' => 'code',
            'scope' => 'openid', 'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('x', 64), true)), '+/', '-_'), '=')];
        $this->actingAs($user, 'web')->getJson('/oidc/authorize?'.http_build_query($query))->assertOk()
            ->assertJsonPath('interaction', 'consent')->assertJsonStructure(['transaction', 'csrf_token', 'scopes']);
        $logout = $this->getJson('/oidc/logout')->assertOk()->assertJsonPath('interaction', 'logout');
        $this->postJson('/oidc/logout/confirm', ['transaction' => $logout->json('transaction'), 'approve' => '0'])
            ->assertOk()->assertJsonPath('cancelled', true);
        $logout = $this->getJson('/oidc/logout')->assertOk();
        $this->postJson('/oidc/logout/confirm', ['transaction' => $logout->json('transaction'), 'approve' => '1'])
            ->assertOk()->assertJsonPath('signed_out', true);
        $this->assertGuest('web');
        $this->assertSame($before, $user->fresh()->getAttributes());
    }
}
