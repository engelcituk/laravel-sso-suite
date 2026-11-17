<?php

declare(strict_types=1);

namespace LaravelSso\Client\Http;

use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Attach to host password-login/register/reset routes once, after the web middleware. */
final class LocalAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('sso-client.enabled')) {
            return $request->isMethod('GET') && ! $request->expectsJson()
                ? AuthenticationResponse::redirect($request, route('sso.login'))
                : response()->json(['message' => 'Central authentication is enabled.'], 403);
        }
        if ($request->session()->has('sso.identity') || $request->session()->get('sso.auth_mode') === 'sso') {
            /** @var SessionGuard $guard */
            $guard = Auth::guard(config('sso-client.guard'));
            $guard->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $next($request);
    }
}
