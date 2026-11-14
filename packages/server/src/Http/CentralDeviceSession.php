<?php

declare(strict_types=1);

namespace LaravelSso\Server\Http;

use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LaravelSso\Server\DeviceSessions;
use LaravelSso\Server\GlobalSessions;
use Symfony\Component\HttpFoundation\Response;

final class CentralDeviceSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard(config('sso-server.guard'));
        if (! $guard instanceof SessionGuard) {
            throw new \RuntimeException('Central device sessions require Laravel SessionGuard.');
        }
        $devices = app(DeviceSessions::class);
        $user = $guard->user();
        $blocked = (bool) $request->attributes->get('sso.native_recaller_blocked');
        $deviceId = $request->session()->get('sso.device_id');
        if ($user && is_string($deviceId)) {
            $device = $devices->active($deviceId);
            if (! $device || $device->subject !== (string) $user->getAuthIdentifier()) {
                $guard->logoutCurrentDevice();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $devices->forgetCookie();
                $user = null;
                $blocked = true;
            } else {
                DB::table('sso_devices')->where('id', $deviceId)->whereNull('revoked_at')->update(['last_seen' => time()]);
            }
        } elseif ($user) {
            // Track existing native authenticated sessions without inferring a fresh password check.
            $passwordHash = $request->session()->get('password_hash_'.config('sso-server.guard'));
            if (is_string($passwordHash) && ! hash_equals($guard->hashPasswordForCookie($user->getAuthPassword()), $passwordHash)) {
                $guard->logoutCurrentDevice();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $blocked = true;
                $user = null;
            } else {
                $device = $devices->register($request, $user);
                $sid = $request->session()->get('sso.sid');
                if (is_string($sid)) {
                    DB::table('sso_sessions')->where('sid', $sid)->where('subject', (string) $user->getAuthIdentifier())
                        ->whereNull('device_id')->update(['device_id' => $device->id]);
                }
            }
        }
        $sid = $request->session()->get('sso.sid');
        $needsRestore = ! $user || (is_string($sid) && ! app(GlobalSessions::class)->valid($sid));
        $restored = false;
        if ($request->isMethod('GET') && $needsRestore && ! $blocked && ! $request->session()->has('sso.reauthentication')) {
            $restored = $devices->restore($request);
        }
        if ($user && $needsRestore && ! $restored) {
            $guard->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $devices->forgetCookie();
        }

        return $next($request);
    }
}
