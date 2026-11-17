<?php

declare(strict_types=1);

namespace LaravelSso\Server\Http;

use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LaravelSso\Server\Audit;
use LaravelSso\Server\DeviceSessions;
use LaravelSso\Server\Login;
use Symfony\Component\HttpFoundation\Response;

final class DeviceController
{
    private function user(): Authenticatable
    {
        $user = Auth::guard(config('sso-server.guard'))->user();
        abort_unless($user !== null, 401);

        return $user;
    }

    public function index(Request $request, DeviceSessions $devices): Response
    {
        $user = Auth::guard(config('sso-server.guard'))->user();
        if (! $user) {
            return $request->expectsJson() ? response()->json(['message' => 'Authentication required.'], 401)
                : redirect()->guest(app(Login::class)->url());
        }
        $rows = DB::table('sso_devices')->where('subject', (string) $user->getAuthIdentifier())
            ->orderByDesc('last_seen')->orderBy('id')->simplePaginate(20);
        $current = $request->session()->get('sso.device_id');
        $confirmed = $devices->confirmed($request, (string) $user->getAuthIdentifier());
        if ($request->expectsJson()) {
            $items = collect($rows->items())->map(static fn ($row) => [
                'id' => $row->id, 'label' => $row->label, 'user_agent' => $row->user_agent, 'ip_address' => $row->ip_address,
                'auth_time' => (int) $row->auth_time, 'last_seen' => (int) $row->last_seen, 'expires_at' => (int) $row->expires_at,
                'remember_until' => $row->remember_until !== null ? (int) $row->remember_until : null,
                'revoked_at' => $row->revoked_at !== null ? (int) $row->revoked_at : null, 'current' => $row->id === $current,
            ]);

            return response()->json(['devices' => $items, 'password_confirmed' => $confirmed,
                'next_page_url' => $rows->nextPageUrl()])->header('Cache-Control', 'no-store');
        }

        return response()->view('sso-server::devices', compact('rows', 'current', 'confirmed'))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    }

    public function confirm(Request $request): Response
    {
        $user = $this->user();
        $input = $request->validate(['password' => 'required|string|max:1024']);
        $guard = Auth::guard(config('sso-server.guard'));
        if (! $guard instanceof SessionGuard || ! $guard->getProvider()->validateCredentials($user, $input)) {
            app(Audit::class)->record('password.rejected', ['subject' => (string) $user->getAuthIdentifier()]);

            return $request->expectsJson() ? response()->json(['message' => 'Password confirmation failed.'], 422)
                : back()->withErrors(['password' => 'La contraseña no es correcta. Inténtalo de nuevo.']);
        }
        $request->session()->put('sso.password_confirmed_at', time());
        $request->session()->put('sso.confirmed_subject', (string) $user->getAuthIdentifier());
        app(Audit::class)->record('password.confirmed', ['subject' => (string) $user->getAuthIdentifier()]);

        return $this->success($request, 'Contraseña confirmada. Ya puedes administrar tus dispositivos.');
    }

    private function requireConfirmation(Request $request, DeviceSessions $devices): string
    {
        $subject = (string) $this->user()->getAuthIdentifier();
        if (! $devices->confirmed($request, $subject)) {
            app(Audit::class)->record('password.required', ['subject' => $subject]);
            if ($request->expectsJson()) {
                abort(403, 'Confirma tu contraseña antes de continuar.');
            }
            throw new HttpResponseException(redirect()->route('sso-server.devices.index')
                ->withErrors(['password' => 'Confirma de nuevo tu contraseña para completar la operación.']));
        }

        return $subject;
    }

    public function label(Request $request, DeviceSessions $devices, string $device): Response
    {
        $subject = (string) $this->user()->getAuthIdentifier();
        abort_unless(DB::table('sso_devices')->where('id', $device)->where('subject', $subject)->exists(), 404);
        $this->requireConfirmation($request, $devices);
        $input = $request->validate(['label' => ['required', 'string', 'max:100', 'regex:/^[^\p{C}]+$/u']]);
        DB::table('sso_devices')->where('id', $device)->where('subject', $subject)->update(['label' => $input['label']]);
        app(Audit::class)->record('device.renamed', ['subject' => $subject, 'device' => $device]);

        return $this->success($request, 'Nombre del dispositivo actualizado.');
    }

    public function revoke(Request $request, DeviceSessions $devices, string $device): Response
    {
        $subject = (string) $this->user()->getAuthIdentifier();
        abort_unless(DB::table('sso_devices')->where('id', $device)->where('subject', $subject)->exists(), 404);
        $this->requireConfirmation($request, $devices);
        abort_unless($devices->revoke($device, $subject), 404);
        if ($request->session()->get('sso.device_id') === $device) {
            return $this->signedOut($request, $devices);
        }

        return $this->success($request, 'Acceso del dispositivo revocado.');
    }

    public function others(Request $request, DeviceSessions $devices): Response
    {
        $subject = $this->requireConfirmation($request, $devices);
        $current = $request->session()->get('sso.device_id');
        abort_unless(is_string($current), 403);
        $devices->revokeAll($subject, $current);

        return $this->success($request, 'Se ha revocado el acceso de los otros dispositivos.');
    }

    public function all(Request $request, DeviceSessions $devices): Response
    {
        $devices->revokeAll($this->requireConfirmation($request, $devices));

        return $this->signedOut($request, $devices);
    }

    private function signedOut(Request $request, DeviceSessions $devices): Response
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard(config('sso-server.guard'));
        $guard->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $devices->forgetCookie();

        return $request->expectsJson() ? response()->json(['signed_out' => true])->header('Cache-Control', 'no-store')
            : redirect(app(Login::class)->url());
    }

    private function success(Request $request, string $message): Response
    {
        return $request->expectsJson() ? response()->json(['message' => $message])->header('Cache-Control', 'no-store')
            : redirect()->route('sso-server.devices.index')->with('status', $message);
    }
}
