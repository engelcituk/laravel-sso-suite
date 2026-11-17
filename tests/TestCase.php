<?php

namespace LaravelSso\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use Laravel\Passport\PassportServiceProvider;
use LaravelSso\Client\IdentityLinks;
use LaravelSso\Client\SsoClientServiceProvider;
use LaravelSso\Contracts\Identity;
use LaravelSso\Server\SsoServerServiceProvider;
use LaravelSso\Tests\Fixtures\CentralUser;
use LaravelSso\Tests\Fixtures\LocalUser;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PassportServiceProvider::class, SsoServerServiceProvider::class, SsoClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        if ($driver = getenv('SSO_TEST_DRIVER')) {
            if (getenv('SSO_DATABASE_LAB') !== '1' || ! in_array($driver, ['mysql', 'pgsql'], true)) {
                throw new \RuntimeException('Real-engine tests require the isolated database laboratory.');
            }
            $isolation = getenv('SSO_TEST_ISOLATION') ?: ($driver === 'mysql' ? 'REPEATABLE READ' : 'READ COMMITTED');
            if (! in_array($isolation, ['READ COMMITTED', 'REPEATABLE READ'], true)) {
                throw new \RuntimeException('Unsupported laboratory transaction isolation level.');
            }
            $app['config']->set('database.connections.testing', [
                'driver' => $driver, 'host' => getenv('SSO_TEST_HOST') ?: ($driver === 'mysql' ? 'mysql' : 'postgres'),
                'port' => getenv('SSO_TEST_PORT') ?: ($driver === 'mysql' ? 3306 : 5432),
                'database' => 'sso_lab_test', 'username' => 'sso_lab', 'password' => getenv('SSO_TEST_PASSWORD'),
                'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
                'collation' => $driver === 'mysql' ? 'utf8mb4_unicode_ci' : null,
                'prefix' => '', 'strict' => true, 'isolation_level' => $isolation,
            ]);
        }
        $app['config']->set('session.driver', 'array');
        $app['config']->set('auth.providers.users.model', CentralUser::class);
        $app['config']->set('auth.guards.local', ['driver' => 'session', 'provider' => 'locals']);
        $app['config']->set('auth.providers.locals', ['driver' => 'eloquent', 'model' => LocalUser::class]);
        $app['config']->set('sso-server.enabled', true);
        $app['config']->set('sso-server.active_column', 'is_active');
        $app['config']->set('sso-server.issuer', 'https://identity.test');
        $app['config']->set('sso-client.enabled', true);
        $app['config']->set('sso-client.integrate_auth', false); // This laboratory hosts both OP and RP; dedicated integration tests enable discovery.
        $app['config']->set('sso-client.issuer', 'https://identity.test');
        $app['config']->set('sso-client.client_id', '00000000-0000-4000-8000-000000000001');
        $app['config']->set('sso-client.guard', 'local');
        $app['config']->set('sso-client.user_model', LocalUser::class);
        $app['config']->set('sso-client.provision', 'restricted'); // Existing-account laboratory; greenfield tests exercise the new default separately.
        $app['config']->set('sso-client.create_user', static fn (Identity $identity) => LocalUser::create(
            array_filter(array_intersect_key($identity->attributes, array_flip(['name', 'email'])), is_string(...))
            + ['password' => bcrypt('fixture-local-password')]));
        $app['config']->set('sso-client.redirect_uri', 'https://one.test/sso/callback');
        $app['config']->set('sso-client.session_status_url', 'https://identity.test/oidc/session');
        $app['config']->set('sso-client.allowed_urls', [
            'https://identity.test/.well-known/openid-configuration', 'https://identity.test/oidc/authorize',
            'https://identity.test/oidc/token', 'https://identity.test/oidc/jwks', 'https://identity.test/oidc/userinfo', 'https://identity.test/oidc/session',
        ]);
        $dir = __DIR__.'/.keys';
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        if (! file_exists($dir.'/private.pem')) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            file_put_contents($dir.'/private.pem', $private);
            file_put_contents($dir.'/public.pem', openssl_pkey_get_details($key)['key']);
        }
        $app['config']->set('sso-server.private_key', $dir.'/private.pem');
        $app['config']->set('sso-server.public_keys', ['initial' => $dir.'/public.pem']);
        $app['config']->set('passport.private_key', file_get_contents($dir.'/private.pem'));
        $app['config']->set('passport.public_key', file_get_contents($dir.'/public.pem'));
        Passport::$validateKeyPermissions = false;
    }

    protected function defineRoutes($router): void
    {
        $router->get('/login', fn () => 'Central login')->name('login');
        $router->middleware(['web', 'sso.session:strict'])->get('/protected', fn () => [
            'id' => auth('local')->id(), 'sub' => session('sso.identity.sub'),
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        if (getenv('SSO_DATABASE_LAB') === '1' && getenv('SSO_TEST_DRIVER')) {
            Schema::dropAllTables();
        }
        $this->loadMigrationsFrom(__DIR__.'/../vendor/laravel/passport/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../packages/server/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../packages/client/database/migrations');
        Schema::create('central_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table): void {
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->collation('utf8mb4_bin');
            }
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    protected function linkedUser(string $issuer, string $subject, array $attributes = []): LocalUser
    {
        $user = LocalUser::create($attributes);
        app(IdentityLinks::class)->link($user, $issuer, $subject);

        return $user;
    }
}
