<?php

declare(strict_types=1);

namespace LaravelSso\Server;

use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Validated;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use LaravelSso\Server\Http\CentralDeviceSession;
use LaravelSso\Server\Http\CodeAuthorizationController;
use LaravelSso\Server\Http\DeviceController;
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
            $this->commands([Console\InstallCommand::class, Console\DoctorCommand::class, Console\KeysCommand::class, Console\RegisterClientCommand::class, Console\DeliverLogoutCommand::class, Console\PruneStateCommand::class]);
        }
        $this->publishes([__DIR__.'/../config/sso-server.php' => config_path('sso-server.php')], 'sso-server-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'sso-server-migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'sso-server');
        if ($this->app->runningConsoleCommand('sso:doctor-server', 'sso:install-server')) {
            return; // Diagnosis and initial installation must survive invalid runtime configuration.
        }
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
        $kernel = $this->app->make(Kernel::class);
        $kernel->appendMiddlewareToGroup('web', CentralDeviceSession::class);
        $kernel->addToMiddlewarePriorityAfter(StartSession::class, CentralDeviceSession::class);
        Event::listen(Validated::class, function (Validated $event): void {
            if ($event->guard === config('sso-server.guard')) {
                request()->attributes->set('sso.validated_subject', (string) $event->user->getAuthIdentifier());
            }
        });
        Event::listen(Failed::class, function (Failed $event): void {
            if ($event->guard === config('sso-server.guard')) {
                app(Audit::class)->record('auth.failed');
            }
        });
        Event::listen(Login::class, function (Login $event): void {
            if ($event->guard === config('sso-server.guard') && request()->hasSession()) {
                $request = request();
                if ($request->attributes->get('sso.restoring_device')) {
                    return; // Remembered authentication never advances auth_time or login_epoch.
                }
                $guard = Auth::guard($event->guard);
                if (! $guard instanceof SessionGuard) {
                    throw new \RuntimeException('Central device sessions require Laravel SessionGuard.');
                }
                $fresh = $request->attributes->get('sso.validated_subject') === (string) $event->user->getAuthIdentifier();
                Cookie::unqueue($guard->getRecallerName());
                Cookie::queue(Cookie::forget($guard->getRecallerName()));
                if ($event->remember && $guard->viaRemember() && ! $fresh) {
                    $request->attributes->set('sso.native_recaller_blocked', true);
                    $guard->logoutCurrentDevice();

                    return;
                }
                $old = $request->session()->get('sso.device_id');
                if (is_string($old)) {
                    app(DeviceSessions::class)->revoke($old);
                }
                if (is_string($oldSid = $request->session()->get('sso.sid'))) {
                    app(GlobalSessions::class)->revoke($oldSid);
                }
                $request->session()->forget(['sso.sid', 'sso.password_confirmed_at', 'sso.confirmed_subject']);
                request()->session()->put('sso.auth_time', time());
                request()->session()->put('sso.login_epoch', bin2hex(random_bytes(16)));
                if ($fresh) {
                    $request->session()->put('sso.password_confirmed_at', time());
                    $request->session()->put('sso.confirmed_subject', (string) $event->user->getAuthIdentifier());
                }
                app(DeviceSessions::class)->register($request, $event->user, $event->remember || ($fresh && $request->boolean('remember')));
                app(Audit::class)->record('auth.login', ['subject' => (string) $event->user->getAuthIdentifier()]);
            }
        });
        $onLogout = function (Logout|CurrentDeviceLogout $event): void {
            if ($event->guard === config('sso-server.guard') && request()->hasSession()) {
                $device = request()->session()->get('sso.device_id');
                if (is_string($device)) {
                    app(DeviceSessions::class)->revoke($device);
                }
                $sid = request()->session()->get('sso.sid');
                if (is_string($sid)) {
                    app(GlobalSessions::class)->revoke($sid);
                }
                app(DeviceSessions::class)->forgetCookie();
                request()->session()->forget(['sso.device_id', 'sso.password_confirmed_at', 'sso.confirmed_subject']);
            }
        };
        Event::listen(Logout::class, $onLogout);
        Event::listen(CurrentDeviceLogout::class, $onLogout);
        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            $provider = config('auth.guards.'.config('sso-server.guard').'.provider');
            $model = config('auth.providers.'.$provider.'.model');
            if (is_string($model) && $event->user instanceof $model) {
                app(DeviceSessions::class)->revokeAll((string) $event->user->getAuthIdentifier());
                app(Audit::class)->record('auth.password_reset', ['subject' => (string) $event->user->getAuthIdentifier()]);
            }
        });
        Route::middleware(['web', 'throttle:30,1,sso-devices'])->prefix(config('sso-server.devices_path'))->name('sso-server.devices.')->group(function (): void {
            Route::get('/', [DeviceController::class, 'index'])->name('index');
            Route::post('/confirm', [DeviceController::class, 'confirm'])->middleware('throttle:5,1,sso-device-confirm')->name('confirm');
            Route::post('/revoke-others', [DeviceController::class, 'others'])->name('others');
            Route::post('/revoke-all', [DeviceController::class, 'all'])->name('all');
            Route::post('/{device}/revoke', [DeviceController::class, 'revoke'])->where('device', '[a-f0-9]{64}')->name('revoke');
            Route::post('/{device}/label', [DeviceController::class, 'label'])->where('device', '[a-f0-9]{64}')->name('label');
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
