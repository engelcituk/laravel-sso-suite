<?php

declare(strict_types=1);

namespace LaravelSso\Client\Http;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Integrates conventional session-auth routes; custom routes remain explicit configuration. */
final class IntegrateAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        if (! config('sso-client.integrate_auth', true) || $route === null) {
            return $next($request);
        }
        $middleware = $route->gatherMiddleware();
        $explicit = static fn (string $alias, string $class): bool => count(array_filter($middleware,
            static fn ($item) => is_string($item) && (explode(':', $item, 2)[0] === $alias || explode(':', $item, 2)[0] === $class))) > 0;
        $native = $request->routeIs(...config('sso-client.local_auth_routes', [])) || $request->is(...config('sso-client.local_auth_paths', []));
        if ($native && ! $explicit('sso.local', LocalAuthentication::class)) {
            return app(LocalAuthentication::class)->handle($request, $next);
        }
        if ($explicit('sso.session', EnsureGlobalSession::class)) {
            return $next($request);
        }
        $guard = config('sso-client.guard');
        $protected = false;
        foreach ($middleware as $item) {
            if (! is_string($item)) {
                continue;
            }
            [$name, $guards] = array_pad(explode(':', $item, 2), 2, null);
            if (in_array($name, ['auth', Authenticate::class], true)
                && ($guards === $guard || ($guards === null && config('auth.defaults.guard') === $guard))) {
                $protected = true;
            }
        }
        if ($protected && (config('sso-client.enabled') || $request->session()->has('sso.identity') || $request->session()->get('sso.auth_mode') === 'sso')) {
            return app(EnsureGlobalSession::class)->handle($request, $next, 'strict');
        }

        return $next($request);
    }
}
