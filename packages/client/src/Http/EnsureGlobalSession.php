<?php

declare(strict_types=1);

namespace LaravelSso\Client\Http;

use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LaravelSso\Client\Audit;
use LaravelSso\Client\IdentityLinks;
use LaravelSso\Client\TrustedHttp;
use Symfony\Component\HttpFoundation\Response;

final class EnsureGlobalSession
{
    public function handle(Request $request, Closure $next, string $policy = 'bounded'): Response
    {
        if (! config('sso-client.enabled')) {
            if ($request->session()->has('sso.identity') || $request->session()->get('sso.auth_mode') === 'sso') {
                /** @var SessionGuard $guard */
                $guard = Auth::guard(config('sso-client.guard'));
                $guard->logoutCurrentDevice();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $request->expectsJson() ? response()->json(['message' => 'Authenticate locally after changing modes.'], 401)
                    : AuthenticationResponse::redirect($request, config('sso-client.local_login_url'));
            }

            $guard = Auth::guard(config('sso-client.guard'));
            if (! $guard->check()) {
                return $request->expectsJson() ? response()->json(['message' => 'Unauthenticated.'], 401)
                    : AuthenticationResponse::redirect($request, config('sso-client.local_login_url'));
            }

            return $next($request);
        }
        $identity = $request->session()->get('sso.identity');
        /** @var SessionGuard $guard */
        $guard = Auth::guard(config('sso-client.guard'));
        if (! $guard->check()) {
            return $this->deny($request, $guard);
        }
        $user = $guard->user();
        $valid = $user instanceof Model && is_array($identity) && is_string($identity['iss'] ?? null)
            && $identity['iss'] === config('sso-client.issuer')
            && is_string($identity['sub'] ?? null) && is_string($identity['sid'] ?? null)
            && is_int($identity['checked_at'] ?? null) && is_string($identity['access_token'] ?? null)
            && app(IdentityLinks::class)->matches($user, $identity['iss'], $identity['sub']);
        $binding = DB::table('sso_local_sessions')->where('session_hash', hash('sha256', $request->session()->getId()))->first();
        $valid = $valid && $binding !== null && ! $binding->revoked
            && $binding->user_id === (string) $user->getAuthIdentifier() && $binding->user_type === $user::class
            && ($binding->expires_at === null || $binding->expires_at > time())
            && $binding->issuer === $identity['iss'] && $binding->subject === $identity['sub'] && $binding->sid === $identity['sid'];
        if ($valid && ($policy === 'strict' || time() - $identity['checked_at'] >= max(0, (int) config('sso-client.session_check_interval')))) {
            try {
                $url = config('sso-client.session_status_url');
                $response = app(TrustedHttp::class)->request($url)->withToken($identity['access_token'])->get($url);
                $valid = $response->successful() && $response->json('active') === true
                    && $response->json('sid') === $identity['sid'] && $response->json('sub') === $identity['sub'];
            } catch (\Throwable) {
                $valid = false;
            }
            if ($valid) {
                $request->session()->put('sso.identity.checked_at', time());
            }
        }
        if (! $valid) {
            return $this->deny($request, $guard);
        }

        return $next($request);
    }

    private function deny(Request $request, SessionGuard $guard): Response
    {
        app(Audit::class)->record('session.denied');
        $guard->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->expectsJson() ? response()->json(['message' => 'SSO session is not active.'], 401)
            : AuthenticationResponse::redirect($request, route('sso.login'));
    }
}
