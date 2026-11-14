<?php

declare(strict_types=1);

namespace LaravelSso\Server;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use LaravelSso\Server\Http\CodeAuthorizationController;
use LaravelSso\Server\Http\LogoutEndpointController;
use LaravelSso\Server\Http\ProtocolController;
use LaravelSso\Server\Http\ResourceController;

final class SsoServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sso-server.php', 'sso-server');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Console\KeysCommand::class, Console\RegisterClientCommand::class, Console\DeliverLogoutCommand::class, Console\PruneStateCommand::class]);
        }
        $this->publishes([__DIR__.'/../config/sso-server.php' => config_path('sso-server.php')], 'sso-server-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'sso-server-migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'sso-server');
        if (! config('sso-server.enabled')) {
            return;
        }
        $issuer = config('sso-server.issuer');
        $parts = parse_url($issuer);
        if (! filter_var($issuer, FILTER_VALIDATE_URL) || str_ends_with($issuer, '/')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '')
            || (parse_url($issuer, PHP_URL_SCHEME) !== 'https' && (! config('sso-server.allow_http') || app()->environment('production')))) {
            throw new \RuntimeException('Configure a dedicated HTTPS issuer origin without credentials, path, query or fragment.');
        }
        Passport::tokensCan(array_merge(Passport::$scopes, [
            'openid' => 'Authenticate your identity', 'profile' => 'Read your name', 'email' => 'Read your email',
        ]));
        Event::listen(Login::class, function (Login $event): void {
            if ($event->guard === config('sso-server.guard') && request()->hasSession()) {
                request()->session()->put('sso.auth_time', time());
                request()->session()->put('sso.login_epoch', bin2hex(random_bytes(16)));
            }
        });
        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->guard === config('sso-server.guard') && request()->hasSession()) {
                $sid = request()->session()->get('sso.sid');
                if (is_string($sid)) {
                    app(GlobalSessions::class)->revoke($sid);
                }
            }
        });
        Route::middleware('throttle:'.config('sso-server.protocol_throttle'))->group(function (): void {
            Route::get('/.well-known/openid-configuration', [ProtocolController::class, 'discovery']);
            Route::get('/oidc/jwks', [ProtocolController::class, 'jwks']);
            Route::post('/oidc/token', [ProtocolController::class, 'token']);
            Route::match(['GET', 'POST'], '/oidc/userinfo', [ResourceController::class, 'userinfo']);
            Route::get('/oidc/session', [ResourceController::class, 'status']);
            Route::middleware('web')->name('sso-server.')->group(function (): void {
                Route::match(['GET', 'POST'], '/oidc/authorize', [CodeAuthorizationController::class, 'authorize'])->name('authorize')
                    ->withoutMiddleware([PreventRequestForgery::class,
                        ValidateCsrfToken::class, VerifyCsrfToken::class]);
                Route::post('/oidc/consent', [CodeAuthorizationController::class, 'consent'])->name('consent');
                Route::match(['GET', 'POST'], '/oidc/logout', [LogoutEndpointController::class, 'confirm'])->name('logout-confirm')
                    ->withoutMiddleware([PreventRequestForgery::class,
                        ValidateCsrfToken::class, VerifyCsrfToken::class]);
                Route::post('/oidc/logout/confirm', [LogoutEndpointController::class, 'logout'])->name('logout');
            });
        });
    }
}
