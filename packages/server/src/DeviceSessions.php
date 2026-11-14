<?php

declare(strict_types=1);

namespace LaravelSso\Server;

use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie as BrowserCookie;

final class DeviceSessions
{
    public function cookieName(): string
    {
        return ($this->secureCookie() ? '__Host-' : '').config('sso-server.remember_cookie', 'sso_remember');
    }

    private function secureCookie(): bool
    {
        return ! (config('sso-server.allow_http') && ! app()->environment('production'));
    }

    public function forgetCookie(): void
    {
        Cookie::queue(new BrowserCookie($this->cookieName(), '', 1, '/', null, $this->secureCookie(), true, false, 'lax'));
    }

    private function queueToken(string $id, string $secret, int $expires): void
    {
        Cookie::queue(new BrowserCookie($this->cookieName(), $id.'.'.$secret, $expires,
            '/', null, $this->secureCookie(), true, false, 'lax'));
    }

    public function credentialHash(Authenticatable $user): string
    {
        return hash_hmac('sha256', 'sso-device-password|'.$user->getAuthPassword(), app('encrypter')->getKey());
    }

    public function active(string $id, bool $lock = false): ?object
    {
        $query = DB::table('sso_devices')->where('id', $id);
        if ($lock) {
            if (DB::transactionLevel() === 0) {
                throw new \LogicException('A device lock requires a transaction.');
            }
            $query->lockForUpdate();
        }
        $device = $query->first();
        $user = $device ? app(GlobalSessions::class)->activeUser($device->subject) : null;

        return $device && $device->revoked_at === null && $device->expires_at > time() && $user
            && hash_equals($device->credential_hash, $this->credentialHash($user)) ? $device : null;
    }

    /** Called only after the application's native SessionGuard has authenticated the user. */
    public function register(Request $request, Authenticatable $user, bool $remember = false): object
    {
        $subject = (string) $user->getAuthIdentifier();
        if (! app(GlobalSessions::class)->activeUser($subject)) {
            throw new \RuntimeException('The central identity is inactive.');
        }
        $remember = $remember && config('sso-server.remember_enabled') && $user->getAuthPassword() !== '';
        $now = time();
        $expires = $now + max(1, (int) config($remember ? 'sso-server.remember_ttl' : 'sso-server.session_max'));
        $id = bin2hex(random_bytes(32));
        $agent = mb_substr($request->userAgent() ?? '', 0, 512);
        $token = DB::transaction(function () use ($id, $subject, $agent, $request, $user, $now, $expires, $remember): ?array {
            DB::table('sso_devices')->insert([
                'id' => $id, 'subject' => $subject, 'label' => $this->defaultLabel($agent), 'user_agent' => $agent,
                'ip_address' => config('sso-server.device_record_ip') ? mb_substr($request->ip() ?? '', 0, 45) : null,
                'credential_hash' => $this->credentialHash($user), 'auth_time' => $request->session()->get('sso.auth_time', $now),
                'created_at' => $now, 'last_seen' => $now, 'expires_at' => $expires, 'remember_until' => $remember ? $expires : null,
            ]);
            app(Audit::class)->record('device.registered', ['subject' => $subject, 'device' => $id], ['remembered' => (bool) $remember]);

            return $remember ? $this->newToken($id, $expires) : null;
        }, 5);
        $request->session()->put('sso.device_id', $id);
        $request->session()->forget('sso.remembered');
        if ($token !== null) {
            $this->queueToken($token['id'], $token['secret'], $expires);
        } else {
            $this->forgetCookie();
        }

        return DB::table('sso_devices')->where('id', $id)->first();
    }

    /** @return array{id: string, secret: string} */
    private function newToken(string $deviceId, int $expires): array
    {
        $token = ['id' => bin2hex(random_bytes(32)), 'secret' => bin2hex(random_bytes(32))];
        DB::table('sso_remember_tokens')->insert([
            'id' => $token['id'], 'device_id' => $deviceId, 'token_hash' => hash('sha256', $token['secret']),
            'created_at' => time(), 'expires_at' => $expires,
        ]);

        return $token;
    }

    /** Single-use persistent credential. A verified replay revokes its entire device. */
    public function restore(Request $request): bool
    {
        $cookie = $request->cookie($this->cookieName());
        if (! config('sso-server.remember_enabled') || ! is_string($cookie)
            || preg_match('/^([a-f0-9]{64})\.([a-f0-9]{64})$/D', $cookie, $parts) !== 1) {
            if ($cookie !== null) {
                $this->forgetCookie();
            }

            return false;
        }
        $result = DB::transaction(function () use ($parts, $request): ?array {
            $token = DB::table('sso_remember_tokens')->where('id', $parts[1])->first();
            if (! $token) {
                return null;
            }
            // Device -> session is the shared lock order for restore, exchange and revocation.
            $device = DB::table('sso_devices')->where('id', $token->device_id)->lockForUpdate()->first();
            $token = DB::table('sso_remember_tokens')->where('id', $parts[1])->lockForUpdate()->first();
            if (! $device || ! $token || ! hash_equals($token->token_hash, hash('sha256', $parts[2]))) {
                return null; // Guessed secrets cannot revoke someone else's device.
            }
            if ($token->used_at !== null) {
                $this->revoke($device->id);
                app(Audit::class)->record('remember.replayed', ['subject' => $device->subject, 'device' => $device->id]);

                return null;
            }
            $active = $this->active($device->id, true);
            if (! $active || $device->remember_until === null || $device->remember_until <= time() || $token->expires_at <= time()) {
                $this->revoke($device->id);
                app(Audit::class)->record('remember.expired', ['subject' => $device->subject, 'device' => $device->id]);

                return null;
            }
            /** @var SessionGuard $guard */
            $guard = Auth::guard(config('sso-server.guard'));
            if ($guard->check() && ((string) $guard->id() !== $device->subject
                || $request->session()->get('sso.device_id') !== $device->id)) {
                return null; // Never replace another authenticated browser identity.
            }
            DB::table('sso_remember_tokens')->where('id', $token->id)->update(['used_at' => time()]);
            DB::table('sso_devices')->where('id', $device->id)->update(['last_seen' => time()]);
            $next = $this->newToken($device->id, (int) $device->remember_until);
            $sid = bin2hex(random_bytes(32));
            DB::table('sso_sessions')->insert([
                'sid' => $sid, 'device_id' => $device->id, 'subject' => $device->subject,
                'auth_time' => $device->auth_time, 'last_seen' => time(),
                'expires_at' => min((int) $device->expires_at, time() + (int) config('sso-server.session_max')), 'revoked' => false,
            ]);

            return ['device' => $device, 'token' => $next, 'sid' => $sid];
        }, 5);
        if ($result === null) {
            $this->forgetCookie();

            return false;
        }
        $device = $result['device'];
        $user = app(GlobalSessions::class)->activeUser($device->subject);
        if (! $user) {
            $this->revoke($device->id);
            $this->forgetCookie();

            return false;
        }
        $request->attributes->set('sso.restoring_device', $device);
        try {
            /** @var SessionGuard $guard */
            $guard = Auth::guard(config('sso-server.guard'));
            $guard->login($user, false);
            $request->session()->regenerate();
            $request->session()->put('sso.device_id', $device->id);
            $request->session()->put('sso.sid', $result['sid']);
            $request->session()->put('sso.auth_time', (int) $device->auth_time);
            $request->session()->put('sso.remembered', true);
            $request->session()->forget(['sso.password_confirmed_at', 'sso.confirmed_subject', 'sso.login_epoch']);
            $this->queueToken($result['token']['id'], $result['token']['secret'], (int) $device->remember_until);
            app(Audit::class)->record('remember.restored', ['subject' => $device->subject, 'device' => $device->id, 'sid' => $result['sid']]);
        } finally {
            $request->attributes->remove('sso.restoring_device');
        }

        return true;
    }

    public function revoke(string $id, ?string $subject = null): bool
    {
        return DB::transaction(function () use ($id, $subject): bool {
            $device = DB::table('sso_devices')->where('id', $id)->lockForUpdate()->first();
            if (! $device || ($subject !== null && $device->subject !== $subject)) {
                return false;
            }
            DB::table('sso_devices')->where('id', $id)->update(['revoked_at' => $device->revoked_at ?? time()]);
            if ($device->revoked_at === null) {
                app(Audit::class)->record('device.revoked', ['subject' => $device->subject, 'device' => $id]);
            }
            foreach (DB::table('sso_sessions')->where('device_id', $id)->orderBy('sid')->lockForUpdate()->pluck('sid') as $sid) {
                app(GlobalSessions::class)->revokeSession($sid);
            }

            return true;
        }, 5);
    }

    public function revokeAll(string $subject, ?string $except = null): void
    {
        foreach (DB::table('sso_devices')->where('subject', $subject)->orderBy('id')->pluck('id') as $id) {
            if ($id !== $except) {
                $this->revoke($id, $subject);
            }
        }
        // Include pre-device sessions as well; never infer a device from a browser's UA.
        foreach (DB::table('sso_sessions')->where('subject', $subject)->whereNull('device_id')->pluck('sid') as $sid) {
            app(GlobalSessions::class)->revokeSession($sid);
        }
    }

    public function confirmed(Request $request, string $subject): bool
    {
        $at = $request->session()->get('sso.password_confirmed_at');

        return is_int($at) && $at <= time() && $at > time() - max(1, (int) config('sso-server.device_password_timeout'))
            && $request->session()->get('sso.confirmed_subject') === $subject;
    }

    private function defaultLabel(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge', str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome', str_contains($agent, 'Safari/') => 'Safari', default => 'Navegador',
        };
        $platform = match (true) {
            str_contains($agent, 'Android') => 'Android', str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows', str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux', default => '',
        };

        return $browser.($platform !== '' ? ' en '.$platform : '');
    }
}
