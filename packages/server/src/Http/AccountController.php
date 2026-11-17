<?php

declare(strict_types=1);

namespace LaravelSso\Server\Http;

use Illuminate\Auth\SessionGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use LaravelSso\Server\Accounts;
use LaravelSso\Server\Login;
use LaravelSso\Server\PasswordRecovery;
use Symfony\Component\HttpFoundation\Response;

final class AccountController
{
    private function enabled(bool $registration = false): void
    {
        abort_unless(app(Login::class)->usesPackageAuthentication()
            && ($registration ? config('sso-server.registration') === 'open' : config('sso-server.password_reset')), 404);
    }

    private function form(Request $request, string $interaction, array $data = []): Response
    {
        $data += ['interaction' => $interaction, 'csrf_token' => $request->session()->token()];

        return ($request->expectsJson() || ! config('sso-server.login_ui', true)
            ? response()->json($data) : response()->view('sso-server::account', $data))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    }

    public function registrationForm(Request $request): Response
    {
        $this->enabled(true);

        return $this->form($request, 'register');
    }

    public function register(Request $request, Accounts $accounts): Response
    {
        $this->enabled(true);
        $accounts->create($request->only('name', 'email', 'password', 'password_confirmation'));

        return ($request->expectsJson() ? response()->json(['registered' => true, 'login_url' => app(Login::class)->url()], 201)
            : redirect(app(Login::class)->url())->with('status', 'Cuenta creada. Inicia sesión; un operador debe asignarte aplicaciones.'))
            ->header('Cache-Control', 'no-store');
    }

    public function forgotForm(Request $request): Response
    {
        $this->enabled();

        return $this->form($request, 'forgot_password');
    }

    public function forgot(Request $request, PasswordRecovery $recovery): Response
    {
        $this->enabled();
        $input = $request->validate(['email' => 'required|string|email|max:255']);
        $recovery->settings();
        try {
            $recovery->send($input['email']);
        } catch (\Throwable) {
            // Keep the public response independent of account existence and SMTP failures.
            Log::warning('Central password recovery delivery failed; check private mail configuration.');
        }
        $message = 'Si la cuenta existe y puede recibir correo, recibirás instrucciones para establecer la contraseña.';

        return ($request->expectsJson() ? response()->json(['accepted' => true, 'message' => $message], 202)
            : redirect()->route('sso-server.auth.forgot')->with('status', $message))->header('Cache-Control', 'no-store');
    }

    public function resetForm(Request $request, string $token): Response
    {
        $this->enabled();

        return $this->form($request, 'reset_password', ['token' => $token, 'email' => is_string($request->query('email')) ? $request->query('email') : '']);
    }

    public function reset(Request $request, Accounts $accounts, PasswordRecovery $recovery): Response
    {
        $this->enabled();
        $input = $request->validate(['email' => 'required|string|email|max:255', 'token' => 'required|string|size:64',
            'password' => $accounts->passwordRules()]);
        $input['password_confirmation'] = $request->input('password_confirmation');
        if ($recovery->reset($input) !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => ['The reset link is invalid or expired.']]);
        }
        $guard = Auth::guard(config('sso-server.guard'));
        $current = $guard->user();
        if ($guard instanceof SessionGuard && $current instanceof Model
            && strtolower((string) $current->getAttribute('email')) === strtolower($input['email'])) {
            $guard->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return ($request->expectsJson() ? response()->json(['password_reset' => true, 'login_url' => app(Login::class)->url()])
            : redirect(app(Login::class)->url())->with('status', 'Contraseña actualizada. Inicia sesión de nuevo.'))
            ->header('Cache-Control', 'no-store');
    }
}
