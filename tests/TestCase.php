<?php

namespace LaravelSso\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use Laravel\Passport\PassportServiceProvider;
use LaravelSso\Client\SsoClientServiceProvider;
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
        $app['config']->set('session.driver', 'array');
        $app['config']->set('auth.providers.users.model', CentralUser::class);
        $app['config']->set('auth.guards.local', ['driver' => 'session', 'provider' => 'locals']);
        $app['config']->set('auth.providers.locals', ['driver' => 'eloquent', 'model' => LocalUser::class]);
        $app['config']->set('sso-server.enabled', true);
        $app['config']->set('sso-server.issuer', 'https://identity.test');
        $app['config']->set('sso-client.enabled', true);
        $app['config']->set('sso-client.issuer', 'https://identity.test');
        $app['config']->set('sso-client.client_id', 'client-one');
        $app['config']->set('sso-client.guard', 'local');
        $app['config']->set('sso-client.user_model', LocalUser::class);
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
            'id' => auth('local')->id(), 'sub' => auth('local')->user()->sso_subject,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
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
            $table->id();
            $table->string('sso_issuer', 191);
            $table->string('sso_subject', 191);
            $table->unique(['sso_issuer', 'sso_subject']);
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });
    }
}
