<?php

namespace LaravelSso\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LaravelSso\Contracts\AuditEvent;
use LaravelSso\Server\ClientDefinition;
use LaravelSso\Server\Clients;
use LaravelSso\Server\Contracts\RegistersClients;
use LaravelSso\Server\CreatedClient;
use LaravelSso\Tests\Fixtures\CentralUser;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

final class ClientRegistrationServiceTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('sso-server.enabled', false);
        $app['config']->set('sso-client.enabled', false);
    }

    public function test_container_service_creates_oauth_and_sso_records_without_enabling_protocol_or_changing_users(): void
    {
        $user = CentralUser::create(['name' => 'Host user', 'email' => 'host@example.test', 'password' => bcrypt('keep-host-password')]);
        $before = $user->fresh()->getAttributes();
        $columns = Schema::getColumnListing('central_users');
        Http::preventStrayRequests();
        $registry = app(RegistersClients::class);
        $this->assertInstanceOf(Clients::class, $registry);
        $created = $registry->create(new ClientDefinition('Example client', 'https://app.test/sso/callback'));
        $oauth = DB::table('oauth_clients')->where('id', $created->id)->first();
        $metadata = DB::table('sso_clients')->where('client_id', $created->id)->first();
        $this->assertSame('Example client', $oauth->name);
        $this->assertSame(['https://app.test/sso/callback'], json_decode($oauth->redirect_uris, true));
        $this->assertNotNull($created->plainSecret());
        $this->assertNotSame($created->plainSecret(), $oauth->secret);
        $this->assertTrue(Hash::check($created->plainSecret(), $oauth->secret));
        $this->assertTrue((bool) $metadata->require_pkce);
        $this->assertTrue((bool) $metadata->restrict_users);
        $this->assertFalse((bool) $metadata->auto_approve);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame($columns, Schema::getColumnListing('central_users'));
        $this->assertSame(0, DB::table('sso_client_users')->count());
        $this->assertFalse(config('sso-server.enabled'));
        $this->get('/.well-known/openid-configuration')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_explicit_options_preserve_public_pkce_and_trusted_logout_metadata(): void
    {
        config(['sso-server.backchannel_allowed_urls' => ['https://app.test/backchannel']]);
        $created = app(RegistersClients::class)->create(new ClientDefinition('Public client', 'https://app.test/cb',
            public: true, firstParty: true, restrictUsers: false, backchannelLogoutUri: 'https://app.test/backchannel',
            postLogoutRedirectUri: 'https://app.test/done'));
        $this->assertNull($created->plainSecret());
        $this->assertNull(DB::table('oauth_clients')->where('id', $created->id)->value('secret'));
        $metadata = DB::table('sso_clients')->where('client_id', $created->id)->first();
        $this->assertTrue((bool) $metadata->require_pkce);
        $this->assertTrue((bool) $metadata->auto_approve);
        $this->assertFalse((bool) $metadata->restrict_users);
        $this->assertSame('https://app.test/backchannel', $metadata->backchannel_logout_uri);
        $this->assertSame(['https://app.test/done'], json_decode($metadata->post_logout_redirect_uris, true));
    }

    public static function invalidDefinitions(): array
    {
        return [
            'empty name' => [['name' => ''], []],
            'long name' => [['name' => str_repeat('a', 256)], []],
            'http callback' => [['redirectUri' => 'http://app.test/cb'], []],
            'callback credentials' => [['redirectUri' => 'https://user:password@app.test/cb'], []],
            'callback fragment' => [['redirectUri' => 'https://app.test/cb#fragment'], []],
            'callback control' => [['redirectUri' => "https://app.test/cb\n"], []],
            'public without pkce' => [['public' => true, 'requirePkce' => false], ['--public' => true, '--allow-confidential-without-pkce' => true]],
            'untrusted backchannel' => [['backchannelLogoutUri' => 'https://app.test/backchannel'], ['--backchannel' => 'https://app.test/backchannel']],
            'http logout' => [['postLogoutRedirectUri' => 'http://app.test/done'], ['--post-logout' => 'http://app.test/done']],
        ];
    }

    #[DataProvider('invalidDefinitions')]
    public function test_service_and_artisan_share_validation_and_leave_no_partial_records(array $overrides, array $options): void
    {
        $parameters = $overrides + ['name' => 'Example client', 'redirectUri' => 'https://app.test/cb'];
        try {
            app(RegistersClients::class)->create(new ClientDefinition(...$parameters));
            $this->fail('Invalid client accepted by the service.');
        } catch (ValidationException $error) {
            $this->assertNotEmpty($error->errors());
        }
        $this->assertSame(1, Artisan::call('sso:client', ['name' => $parameters['name'], 'redirect' => $parameters['redirectUri']] + $options));
        $this->assertSame(0, DB::table('oauth_clients')->count());
        $this->assertSame(0, DB::table('sso_clients')->count());
    }

    public function test_development_http_option_never_permits_http_in_production(): void
    {
        $definition = new ClientDefinition('Local app', 'http://127.0.0.2:9301/sso/callback');
        config(['sso-server.allow_http' => true]);
        app(RegistersClients::class)->validate($definition);
        $environment = $this->app['env'];
        try {
            $this->app['env'] = 'production';
            try {
                app(RegistersClients::class)->create($definition);
                $this->fail('Production must reject HTTP.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('redirect_uri', $error->errors());
            }
        } finally {
            $this->app['env'] = $environment;
        }
        $this->assertSame(0, DB::table('oauth_clients')->count());
    }

    public function test_preflight_does_not_grant_trust_and_service_never_rewrites_the_host_environment(): void
    {
        $path = dirname(__DIR__).'/.runtime/client-registration-service/env-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($path);
        $original = "APP_KEY=preserve\nDB_DATABASE=preserve\nSSO_BACKCHANNEL_ALLOWED_URLS=\n";
        file_put_contents($path.'/.env', $original);
        $environmentPath = $this->app->environmentPath();
        $this->app->useEnvironmentPath($path);
        try {
            config(['sso-server.backchannel_allowed_urls' => []]);
            $definition = new ClientDefinition('Example client', 'https://app.test/cb', backchannelLogoutUri: 'https://app.test/backchannel');
            $registry = app(RegistersClients::class);
            $registry->validate($definition, trustBackchannel: true);
            try {
                $registry->create($definition);
                $this->fail('A preflight must not authorize outbound traffic.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('backchannel_logout_uri', $error->errors());
            }
            $this->assertSame([], config('sso-server.backchannel_allowed_urls'));
            config(['sso-server.backchannel_allowed_urls' => ['https://app.test/backchannel']]);
            $registry->create($definition);
            $this->assertSame($original, file_get_contents($path.'/.env'));
            $this->assertSame(1, DB::table('sso_clients')->count());
        } finally {
            $this->app->useEnvironmentPath($environmentPath);
            File::deleteDirectory($path);
        }
    }

    public function test_metadata_failure_rolls_back_oauth_record_and_audit(): void
    {
        Event::fake([AuditEvent::class]);
        Schema::drop('sso_clients');
        try {
            app(RegistersClients::class)->create(new ClientDefinition('Example client', 'https://app.test/cb'));
            $this->fail('Missing metadata table must fail.');
        } catch (QueryException) {
            $this->assertSame(0, DB::table('oauth_clients')->count());
        }
        Event::assertNotDispatched(AuditEvent::class);
    }

    public function test_host_transaction_can_rollback_both_records_and_registration_event(): void
    {
        Event::fake([AuditEvent::class]);
        try {
            DB::transaction(function (): void {
                app(RegistersClients::class)->create(new ClientDefinition('Example client', 'https://app.test/cb'));
                $this->assertSame(1, DB::table('sso_clients')->count());
                Event::assertNotDispatched(AuditEvent::class);
                throw new \RuntimeException('Host save failed.');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Host save failed.', $error->getMessage());
        }
        $this->assertSame(0, DB::table('oauth_clients')->count());
        $this->assertSame(0, DB::table('sso_clients')->count());
        Event::assertNotDispatched(AuditEvent::class);
    }

    public function test_creation_result_exposes_secret_explicitly_and_redacts_json_debug_serialization_and_audit(): void
    {
        Event::fake([AuditEvent::class]);
        $created = app(RegistersClients::class)->create(new ClientDefinition('Example client', 'https://app.test/cb'));
        $secret = $created->plainSecret();
        $this->assertNotNull($secret);
        $this->assertSame(['id' => $created->id], json_decode(json_encode($created), true));
        $this->assertStringNotContainsString($secret, serialize($created));
        $restored = unserialize(serialize($created));
        $this->assertSame($created->id, $restored->id);
        $this->assertNull($restored->plainSecret());
        ob_start();
        var_dump($created);
        $debug = ob_get_clean();
        $this->assertStringNotContainsString($secret, $debug);
        Event::assertDispatchedTimes(AuditEvent::class, 1);
        Event::assertDispatched(AuditEvent::class, fn ($event) => $event->name === 'client.registered'
            && ! str_contains(json_encode($event), $secret) && ! str_contains(json_encode($event), 'Example client'));
    }

    public function test_artisan_is_an_adapter_to_the_injectable_contract(): void
    {
        $definition = null;
        $id = '00000000-0000-4000-8000-000000000042';
        $registry = Mockery::mock(RegistersClients::class);
        $registry->shouldReceive('validate')->once()->withArgs(function ($received, $trust) use (&$definition): bool {
            $definition = $received;

            return $trust === false;
        });
        $registry->shouldReceive('create')->once()->withArgs(function ($received) use (&$definition): bool {
            return $received === $definition;
        })
            ->andReturn(new CreatedClient($id, 'one-time-creation-secret'));
        $this->app->instance(RegistersClients::class, $registry);
        $this->assertSame(0, Artisan::call('sso:client', ['name' => 'Example client', 'redirect' => 'https://app.test/cb',
            '--first-party' => true, '--allow-all-users' => true, '--allow-confidential-without-pkce' => true,
            '--backchannel' => 'https://app.test/backchannel', '--post-logout' => 'https://app.test/done']));
        $this->assertSame('Example client', $definition->name);
        $this->assertFalse($definition->public);
        $this->assertTrue($definition->firstParty);
        $this->assertFalse($definition->requirePkce);
        $this->assertFalse($definition->restrictUsers);
        $this->assertSame('https://app.test/backchannel', $definition->backchannelLogoutUri);
        $this->assertSame('https://app.test/done', $definition->postLogoutRedirectUri);
        $output = Artisan::output();
        $this->assertStringContainsString('SSO_CLIENT_ID='.$id, $output);
        $this->assertStringContainsString('SSO_CLIENT_SECRET=one-time-creation-secret', $output);
        $this->assertSame(0, DB::table('oauth_clients')->count());
    }

    public function test_host_crud_can_authorize_validate_and_call_the_same_service_without_a_package_admin_backend(): void
    {
        $admin = CentralUser::create(['name' => 'Host admin', 'email' => 'admin@example.test', 'password' => bcrypt('preserve-password')]);
        $before = $admin->fresh()->getAttributes();
        Gate::define('manage-sso-clients', fn (CentralUser $user) => $user->id === $admin->id);
        Route::middleware('web')->post('/host/clients', function (Request $request, RegistersClients $clients) {
            Gate::authorize('manage-sso-clients');
            $input = $request->validate(['name' => 'required|string', 'redirect_uri' => 'required|string']);
            $created = $clients->create(new ClientDefinition($input['name'], $input['redirect_uri']));

            return response()->json(['client_id' => $created->id, 'client_secret' => $created->plainSecret()], 201)->header('Cache-Control', 'no-store');
        });
        $this->postJson('/host/clients', ['name' => 'Example client', 'redirect_uri' => 'https://app.test/cb'])->assertForbidden();
        $this->assertSame(0, DB::table('oauth_clients')->count());
        $response = $this->actingAs($admin, 'web')->postJson('/host/clients', ['name' => 'Example client', 'redirect_uri' => 'https://app.test/cb',
            'restrict_users' => false, 'require_pkce' => false, 'is_admin' => true])->assertCreated();
        $this->assertNotNull($response->json('client_secret'));
        $metadata = DB::table('sso_clients')->where('client_id', $response->json('client_id'))->first();
        $this->assertTrue((bool) $metadata->restrict_users);
        $this->assertTrue((bool) $metadata->require_pkce);
        $this->assertSame($before, $admin->fresh()->getAttributes());
        $this->postJson('/sso/admin/clients', [])->assertNotFound();
    }
}
