<?php

namespace LaravelSso\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LaravelSso\Client\EloquentProvisioner;
use LaravelSso\Client\Events\IdentityProvisioned;
use LaravelSso\Client\Exceptions\ProtocolException;
use LaravelSso\Client\IdentityLinks;
use LaravelSso\Contracts\Identity;
use LaravelSso\Server\Http\ProtocolController;
use LaravelSso\Server\SigningKeys;
use LaravelSso\Tests\Fixtures\LocalUser;
use LaravelSso\Tests\Fixtures\SsoOnlyUser;

final class OptionalClientTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('sso-client.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'sso.local'])->get('/native-login', fn () => 'Local login');
        Route::middleware(['web', 'sso.local'])->post('/native-login', function (Request $request) {
            if (! Auth::guard('local')->attempt($request->only('email', 'password'))) {
                return response()->json(['ok' => false], 401);
            }
            $request->session()->regenerate();

            return response()->json(['ok' => true, 'id' => Auth::guard('local')->id()]);
        });
    }

    private function user(): LocalUser
    {
        return LocalUser::create(['name' => 'Existing account', 'email' => 'local@example.test',
            'password' => Hash::make('local-password'), 'remember_token' => str_repeat('r', 60)]);
    }

    private function removeSsoSchema(): void
    {
        foreach (['sso_local_sessions', 'sso_logout_receipts', 'sso_identities'] as $table) {
            Schema::drop($table);
        }
    }

    public function test_disabled_mode_authenticates_normally_without_any_sso_tables_or_http(): void
    {
        $user = $this->user();
        $before = $user->fresh()->getAttributes();
        $this->removeSsoSchema();
        Http::preventStrayRequests();
        $this->getJson('/protected')->assertUnauthorized();
        $this->postJson('/native-login', ['email' => $user->email, 'password' => 'local-password'])
            ->assertOk()->assertJsonPath('id', $user->id);
        $this->getJson('/protected')->assertOk()->assertJsonPath('id', $user->id);
        $this->assertSame($before, $user->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_disabled_routes_remain_disabled_even_when_registered_or_cached(): void
    {
        $this->removeSsoSchema();
        Http::preventStrayRequests();
        $this->get('/sso/login')->assertNotFound();
        $this->get('/sso/callback?code=untrusted')->assertNotFound();
        $this->postJson('/sso/backchannel-logout', ['logout_token' => 'untrusted'])->assertNotFound();
        $this->post('/sso/logout')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_enabled_mode_redirects_local_login_and_rejects_password_posts(): void
    {
        $user = $this->user();
        $before = $user->fresh()->getAttributes();
        config(['sso-client.enabled' => true]);
        $this->get('/native-login')->assertRedirect('/sso/login');
        $this->postJson('/native-login', ['email' => $user->email, 'password' => 'local-password'])->assertForbidden();
        $this->assertGuest('local');
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_routes_registered_while_disabled_can_be_enabled_without_rebooting_provider(): void
    {
        config(['sso-client.enabled' => true]);
        Http::fake(['https://identity.test/.well-known/openid-configuration' => Http::response(
            app(ProtocolController::class)->discovery()->getData(true))]);
        $this->get('/sso/login')->assertRedirect();
        Http::assertSentCount(1);
    }

    public function test_enabling_sso_does_not_promote_a_native_session_or_change_credentials(): void
    {
        $user = $this->user();
        $before = $user->fresh()->getAttributes();
        $this->actingAs($user, 'local');
        config(['sso-client.enabled' => true]);
        Http::preventStrayRequests();
        $this->getJson('/protected')->assertUnauthorized();
        $this->assertGuest('local');
        $this->assertSame($before, $user->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_disabling_sso_requires_fresh_native_authentication_without_querying_sso(): void
    {
        $user = $this->user();
        $before = $user->fresh()->getAttributes();
        $this->actingAs($user, 'local')->withSession(['sso.auth_mode' => 'sso', 'sso.identity' => ['sub' => 'central']]);
        $this->removeSsoSchema();
        Http::preventStrayRequests();
        $this->getJson('/protected')->assertUnauthorized();
        $this->assertGuest('local');
        $this->assertNull(session('sso.identity'));
        $this->postJson('/native-login', ['email' => $user->email, 'password' => 'local-password'])->assertOk();
        $this->getJson('/protected')->assertOk();
        $this->assertSame($before, $user->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_local_login_page_clears_an_old_sso_session_before_native_login(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'local')->withSession(['sso.auth_mode' => 'sso']);
        $this->removeSsoSchema();
        $this->get('/native-login')->assertOk()->assertSee('Local login');
        $this->assertGuest('local');
        $this->assertSame(str_repeat('r', 60), $user->fresh()->remember_token);
    }

    public function test_explicit_linking_keeps_user_id_profile_password_and_host_permissions(): void
    {
        $user = $this->user();
        $before = $user->fresh()->getAttributes();
        Schema::create('host_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
        });
        DB::table('host_permissions')->insert(['user_id' => $user->id]);
        $this->assertSame(0, Artisan::call('sso:link-user', ['user' => $user->id, 'subject' => 'central']));
        config(['sso-client.enabled' => true]);
        $resolved = app(EloquentProvisioner::class)->provision(new Identity('https://identity.test', 'central', 'sid', time(),
            ['name' => 'Remote name', 'email' => 'remote@example.test', 'password' => 'remote-password']));
        $this->assertSame($user->id, $resolved->getAuthIdentifier());
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame(1, DB::table('host_permissions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sso_identities')->count());
        Schema::drop('host_permissions');
    }

    public function test_linking_is_idempotent_and_cannot_reassign_an_identity(): void
    {
        $user = $this->user();
        $other = LocalUser::create(['email' => 'other@example.test']);
        $links = app(IdentityLinks::class);
        $links->link($user, 'https://identity.test', 'central');
        $links->link($user, 'https://identity.test', 'central');
        try {
            $links->link($other, 'https://identity.test', 'central');
            $this->fail('An identity must not change owners implicitly.');
        } catch (ProtocolException) {
            $this->assertSame($user->id, $links->find('https://identity.test', 'central')->getAuthIdentifier());
        }
        $this->assertSame(1, DB::table('sso_identities')->count());
    }

    public function test_matching_email_never_links_an_existing_account_automatically(): void
    {
        $user = $this->user();
        config(['sso-client.enabled' => true]);
        try {
            app(EloquentProvisioner::class)->provision(new Identity('https://identity.test', 'unknown', 'sid', time(), ['email' => $user->email]));
            $this->fail('Email must not link identities.');
        } catch (ProtocolException) {
            $this->assertSame(0, DB::table('sso_identities')->count());
        }
        $this->assertSame(1, LocalUser::count());
    }

    public function test_missing_factory_rejects_automatic_provisioning_without_writing_users(): void
    {
        $user = $this->user();
        $before = $user->fresh()->getAttributes();
        config(['sso-client.enabled' => true, 'sso-client.provision' => 'automatic', 'sso-client.create_user' => null]);
        try {
            app(EloquentProvisioner::class)->provision(new Identity('https://identity.test', 'unknown', 'sid', time()));
            $this->fail('A host factory is required.');
        } catch (ProtocolException) {
            $this->assertSame(0, DB::table('sso_identities')->count());
        }
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame(1, LocalUser::count());
    }

    public function test_rejected_host_policy_rolls_back_user_factory_mapping_and_callbacks(): void
    {
        config(['sso-client.enabled' => true, 'sso-client.provision' => 'automatic', 'sso-client.allowed' => fn () => false]);
        Event::fake([IdentityProvisioned::class]);
        try {
            app(EloquentProvisioner::class)->provision(new Identity('https://identity.test', 'unknown', 'sid', time()));
            $this->fail('The host policy must reject the identity.');
        } catch (ProtocolException) {
            $this->assertSame(0, LocalUser::count());
        }
        $this->assertSame(0, DB::table('sso_identities')->count());
        Event::assertNotDispatched(IdentityProvisioned::class);
    }

    public function test_disabled_doctor_needs_neither_sso_schema_nor_provider_configuration(): void
    {
        $this->removeSsoSchema();
        config(['sso-client.issuer' => null, 'sso-client.client_id' => null, 'sso-client.user_model' => 'MissingHostModel']);
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('sso:doctor', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['mode'], array_column($report['checks'], 'code'));
        Http::assertNothingSent();
    }

    public function test_sso_callback_and_logout_preserve_existing_user_credentials_and_profile(): void
    {
        $user = $this->user();
        $before = $user->fresh()->getAttributes();
        app(IdentityLinks::class)->link($user, 'https://identity.test', 'central');
        config(['sso-client.enabled' => true, 'sso-client.allowed_urls' => [...config('sso-client.allowed_urls'), 'https://identity.test/oidc/logout']]);
        $metadata = app(ProtocolController::class)->discovery()->getData(true);
        Http::fake([
            'https://identity.test/.well-known/openid-configuration' => Http::response($metadata),
            'https://identity.test/oidc/jwks' => Http::response(app(SigningKeys::class)->jwks()),
            'https://identity.test/oidc/userinfo' => Http::response(['sub' => 'central', 'name' => 'Remote name', 'email' => 'remote@example.test']),
            'https://identity.test/oidc/session' => Http::response(['active' => true, 'sub' => 'central', 'sid' => 'sid']),
        ]);
        $this->get('/sso/login')->assertRedirect();
        $state = session('sso.transaction.state');
        $claims = ['iss' => 'https://identity.test', 'sub' => 'central', 'aud' => config('sso-client.client_id'),
            'iat' => time(), 'exp' => time() + 300, 'auth_time' => time(), 'nonce' => session('sso.transaction.nonce'), 'sid' => 'sid'];
        Http::fake(['https://identity.test/oidc/token' => Http::response([
            'access_token' => 'token', 'expires_in' => 300, 'id_token' => app(SigningKeys::class)->sign($claims),
        ])]);
        $this->get('/sso/callback?'.http_build_query(['state' => $state, 'code' => 'code', 'iss' => 'https://identity.test']))->assertRedirect('/');
        $this->assertAuthenticatedAs($user, 'local');
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
        $this->getJson('/protected')->assertOk();
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->post('/sso/logout')->assertRedirect();
        $this->assertGuest('local');
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_linked_identity_cannot_authenticate_a_different_local_user(): void
    {
        $owner = $this->user();
        $other = LocalUser::create(['email' => 'other@example.test', 'password' => Hash::make('other-password')]);
        app(IdentityLinks::class)->link($owner, 'https://identity.test', 'central');
        config(['sso-client.enabled' => true]);
        Http::preventStrayRequests();
        $this->actingAs($other, 'local')->withSession(['sso.identity' => ['iss' => 'https://identity.test', 'sub' => 'central',
            'sid' => 'sid', 'access_token' => 'token', 'checked_at' => time()]])->getJson('/protected')->assertUnauthorized();
        $this->assertGuest('local');
        Http::assertNothingSent();
    }

    public function test_single_client_migration_never_changes_existing_user_schema_or_credentials(): void
    {
        $user = $this->user();
        $before = $user->fresh()->getAttributes();
        $columns = Schema::getColumnListing('users');
        $files = glob(dirname(__DIR__).'/packages/client/database/migrations/*.php');
        $this->assertCount(1, $files);
        $migration = require $files[0];
        $migration->down();
        $this->assertSame($columns, Schema::getColumnListing('users'));
        $this->assertSame($before, $user->fresh()->getAttributes());
        $migration->up();
        $this->assertSame($columns, Schema::getColumnListing('users'));
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertFalse(Schema::hasColumn('users', 'sso_issuer'));
        $this->assertFalse(Schema::hasColumn('users', 'sso_subject'));
        $this->assertTrue(Schema::hasColumns('sso_identities', ['issuer', 'subject', 'user_id', 'user_type']));
    }

    public function test_optional_sso_only_model_behavior_never_removes_stored_credentials(): void
    {
        $user = $this->user();
        $ssoOnly = SsoOnlyUser::findOrFail($user->id);
        $this->assertSame('', $ssoOnly->getAuthPassword());
        try {
            $ssoOnly->password = 'replacement';
            $this->fail('The optional SSO-only model must reject credential assignment.');
        } catch (\LogicException) {
            $this->assertSame($user->password, $user->fresh()->password);
        }
        $this->assertTrue(Hash::check('local-password', $user->fresh()->getAuthPassword()));
        $this->assertTrue(Schema::hasColumns('users', ['password', 'remember_token']));
    }

    public function test_linking_does_not_require_changes_to_the_hosts_morph_map(): void
    {
        $user = $this->user();
        $previous = Relation::requiresMorphMap();
        try {
            Relation::requireMorphMap(true);
            app(IdentityLinks::class)->link($user, 'https://identity.test', 'central');
            config(['sso-client.enabled' => true]);
            $resolved = app(EloquentProvisioner::class)->provision(new Identity('https://identity.test', 'central', 'sid', time()));
            $this->assertSame($user->id, $resolved->getAuthIdentifier());
            $this->assertTrue(app(IdentityLinks::class)->matches($resolved, 'https://identity.test', 'central'));
        } finally {
            Relation::requireMorphMap($previous);
        }
    }
}
