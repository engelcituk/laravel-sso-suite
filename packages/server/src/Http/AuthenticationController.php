<?php

declare(strict_types=1);

namespace LaravelSso\Server\Http;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use LaravelSso\Server\GlobalSessions;
use LaravelSso\Server\Login;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticationController
{
    private function guard(): SessionGuard
    {
        $guard = Auth::guard(config('sso-server.guard'));
        if (! $guard instanceof SessionGuard) {
            throw new \RuntimeException('SSO authentication requires a Laravel SessionGuard.');
        }

        return $guard;
    }

    public function session(Request $request): Response
    {
        return response()->json(['authenticated' => $this->guard()->check(), 'csrf_token' => $request->session()->token(),
            'password_login_available' => app(Login::class)->usesPackageAuthentication()])->header('Cache-Control', 'no-store');
    }

    public function form(Request $request): Response
    {
        if ($request->expectsJson() || ! config('sso-server.login_ui', true)) {
            return $this->session($request);
        }
        if (! app(Login::class)->usesPackageAuthentication()) {
            return redirect(app(Login::class)->url())->header('Cache-Control', 'no-store');
        }

        return response()->view(config('sso-server.login_view') ?: 'sso-server::login', ['field' => config('sso-server.login_field', 'email')])
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    }

    public function login(Request $request, GlobalSessions $sessions, Login $login): Response
    {
        // Never introduce a password-only bypass beside a host login with MFA or other policy.
        abort_unless($login->usesPackageAuthentication(), 404);
        $field = config('sso-server.login_field', 'email');
        $input = $request->validate([$field => 'required|string|max:255', 'password' => 'required|string|max:4096', 'remember' => 'sometimes|boolean']);
        $guard = $this->guard();
        // validate() emits Validated without rehashing or writing host attributes.
        $valid = $guard->validate([$field => $input[$field], 'password' => $input['password']]);
        $user = $guard->getLastAttempted();
        if (! $valid || ! $user || ! $sessions->activeUser((string) $user->getAuthIdentifier())) {
            event(new Failed(config('sso-server.guard'), $user, []));
            throw ValidationException::withMessages([$field => ['The provided credentials are invalid.']]);
        }
        $guard->login($user, false);
        $request->session()->regenerate();
        $destination = $login->destination($request->session()->pull('url.intended', config('sso-server.after_login', '/')));

        return $request->expectsJson() ? response()->json(['authenticated' => true, 'redirect_to' => $destination])->header('Cache-Control', 'no-store')
            : redirect($destination)->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request, Login $login): Response
    {
        $this->guard()->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->expectsJson() ? response()->json(['signed_out' => true])->header('Cache-Control', 'no-store')
            : redirect($login->url())->header('Cache-Control', 'no-store');
    }
}
