<?php

declare(strict_types=1);

namespace LaravelSso\Client\Http;

use Closure;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LaravelSso\Client\Audit;
use LaravelSso\Client\TrustedHttp;
use Symfony\Component\HttpFoundation\Response;

final class EnsureGlobalSession
{
    public function handle(Request $request, Closure $next, string $policy = 'bounded'): Response
    {
        $identity = $request->session()->get('sso.identity');
        /** @var StatefulGuard $guard */
        $guard = Auth::guard(config('sso-client.guard'));
        if (! $guard->check()) {
            return $this->deny($request, $guard);
        }
        $user = $guard->user();
        $valid = $user instanceof Model && is_array($identity) && ($identity['iss'] ?? null) === config('sso-client.issuer')
            && (string) $user->getAttribute('sso_subject') === ($identity['sub'] ?? null)
            && $user->getAttribute('sso_issuer') === $identity['iss'];
        $binding = DB::table('sso_local_sessions')->where('session_hash', hash('sha256', $request->session()->getId()))->first();
        $valid = $valid && $binding !== null && ! $binding->revoked
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

    private function deny(Request $request, StatefulGuard $guard): Response
    {
        app(Audit::class)->record('session.denied');
        $guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->expectsJson() ? response()->json(['message' => 'SSO session is not active.'], 401)
            : redirect()->route('sso.login');
    }
}
