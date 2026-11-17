<?php

namespace LaravelSso\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LaravelSso\Tests\Fixtures\LocalUser;

final class AgnosticClientTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('sso-client.integrate_auth', true);
        $app['config']->set('sso-client.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('web')->get('/login', fn () => 'Host login')->name('login');
        Route::middleware('web')->post('/login', function (Request $request) {
            if (! Auth::guard('local')->attempt($request->only('email', 'password'))) {
                return response()->json(['ok' => false], 422);
            }

            return response()->json(['ok' => true]);
        })->name('login.store');
        Route::middleware(['web', 'auth:local'])->get('/existing-dashboard', fn () => 'Existing dashboard');
        Route::middleware('web')->get('/public-page', fn () => 'Public page');
        Route::middleware(['web', 'auth:web'])->get('/other-guard', fn () => 'Different guard');
    }

    public function test_disabled_package_preserves_native_auth_without_any_sso_tables_or_http(): void
    {
        $user = LocalUser::create(['email' => 'native@example.test', 'password' => bcrypt('password')]);
        $before = $user->fresh()->getAttributes();
        Http::preventStrayRequests();
        foreach (['sso_identities', 'sso_local_sessions', 'sso_logout_receipts'] as $table) {
            Schema::drop($table);
        }
        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->getJson('/existing-dashboard')->assertOk()->assertSee('Existing dashboard');
        Http::assertNothingSent();
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_enabled_mode_automatically_gates_native_routes_before_their_controllers(): void
    {
        config(['sso-client.enabled' => true]);
        Http::preventStrayRequests();
        $this->get('/login')->assertRedirect(route('sso.login'));
        $this->postJson('/login', ['email' => 'native@example.test', 'password' => 'private'])->assertForbidden();
        $this->getJson('/existing-dashboard')->assertUnauthorized();
        $this->get('/existing-dashboard')->assertRedirect(route('sso.login'));
        $this->get('/public-page')->assertOk();
        Http::assertNothingSent();
    }

    public function test_native_authenticated_session_does_not_become_sso_and_other_guards_are_untouched(): void
    {
        $user = LocalUser::create(['email' => 'native@example.test', 'password' => bcrypt('password')]);
        $this->actingAs($user, 'local');
        config(['sso-client.enabled' => true]);
        $this->getJson('/existing-dashboard')->assertUnauthorized();
        $this->assertGuest('local');
        $this->getJson('/other-guard')->assertUnauthorized();
    }

    public function test_inertia_navigation_uses_a_full_browser_visit_without_an_inertia_dependency(): void
    {
        config(['sso-client.enabled' => true]);
        foreach (['/login', '/existing-dashboard'] as $url) {
            $this->withHeaders(['X-Inertia' => 'true', 'Accept' => 'text/html'])->get($url)
                ->assertStatus(409)->assertHeader('X-Inertia-Location', route('sso.login'));
        }
    }

    public function test_custom_routes_are_configurable_and_manual_integration_can_be_selected(): void
    {
        Route::middleware('web')->get('/members/access', fn () => 'Custom login')->name('members.access');
        config(['sso-client.enabled' => true]);
        $this->get('/members/access')->assertSee('Custom login');
        config(['sso-client.local_auth_routes' => ['members.access']]);
        $this->get('/members/access')->assertRedirect(route('sso.login'));
        config(['sso-client.integrate_auth' => false]);
        $this->get('/members/access')->assertSee('Custom login');
    }

    public function test_sso_origin_session_requires_new_local_auth_after_disabling(): void
    {
        $user = LocalUser::create(['email' => 'native@example.test', 'password' => bcrypt('password'), 'remember_token' => 'keep-native-recaller']);
        $before = $user->fresh()->getAttributes();
        $this->actingAs($user, 'local')->withSession(['sso.auth_mode' => 'sso']);
        $this->getJson('/existing-dashboard')->assertUnauthorized();
        $this->assertGuest('local');
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_client_registers_its_cleanup_job_only_when_enabled(): void
    {
        config(['sso-client.enabled' => true]);
        $events = app(Schedule::class)->events();
        $this->assertCount(1, array_filter($events, fn ($event) => str_contains($event->command ?? '', 'sso:prune-client')));
    }
}
