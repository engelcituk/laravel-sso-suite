<?php

declare(strict_types=1);

namespace LaravelSso\Client;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use LaravelSso\Client\Contracts\ProvisionsUsers;
use LaravelSso\Client\Http\EnsureGlobalSession;
use LaravelSso\Client\Http\EnsureSsoEnabled;
use LaravelSso\Client\Http\LocalAuthentication;
use LaravelSso\Client\Http\LoginController;
use LaravelSso\Client\Http\LogoutController;

final class SsoClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sso-client.php', 'sso-client');
        $this->app->bind(ProvisionsUsers::class, EloquentProvisioner::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Console\InstallCommand::class, Console\DoctorCommand::class, Console\PruneStateCommand::class, Console\LinkUserCommand::class]);
        }
        $this->app['router']->aliasMiddleware('sso.session', EnsureGlobalSession::class);
        $this->app['router']->aliasMiddleware('sso.enabled', EnsureSsoEnabled::class);
        $this->app['router']->aliasMiddleware('sso.local', LocalAuthentication::class);
        $this->publishes([__DIR__.'/../config/sso-client.php' => config_path('sso-client.php')], 'sso-client-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'sso-client-migrations');
        if ($this->app->runningConsoleCommand('sso:doctor', 'sso:install-client')) {
            return;
        }
        if (config('sso-client.enabled') && config('session.driver') === 'cookie') {
            throw new \RuntimeException('SSO requires server-side sessions; the cookie session driver is not supported.');
        }
        Route::post('/sso/backchannel-logout', [LogoutController::class, 'backchannel'])->middleware(['sso.enabled', 'throttle:120,1,sso-backchannel']);
        Route::middleware(['web', 'sso.enabled', 'throttle:60,1,sso-client'])->prefix('sso')->name('sso.')->group(function (): void {
            Route::get('login', [LoginController::class, 'redirect'])->name('login');
            Route::get('callback', [LoginController::class, 'callback'])->name('callback');
            Route::post('logout', [LogoutController::class, 'redirect'])->name('logout');
        });
    }
}
