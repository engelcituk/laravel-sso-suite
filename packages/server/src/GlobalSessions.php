<?php

declare(strict_types=1);

namespace LaravelSso\Server;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class GlobalSessions
{
    public function activeUser(string $subject): (Model&Authenticatable)|null
    {
        $guard = config('sso-server.guard');
        $provider = config('auth.guards.'.$guard.'.provider');
        $user = Auth::createUserProvider($provider)?->retrieveById($subject);
        $column = config('sso-server.active_column');

        return $user instanceof Model && (bool) $user->getAttribute($column) ? $user : null;
    }

    public function valid(string $sid, bool $touch = false, bool $lock = false): ?object
    {
        $query = DB::table('sso_sessions')->where('sid', $sid);
        if ($lock) {
            if (DB::transactionLevel() === 0) {
                throw new \LogicException('A session lock requires an enclosing transaction.');
            }
            $query->lockForUpdate();
        }
        $session = $query->first();
        if (! $session || $session->revoked || $session->expires_at <= time()
            || $session->last_seen + config('sso-server.session_idle') <= time()
            || ! $this->activeUser($session->subject)) {
            return null;
        }
        if ($touch) {
            DB::table('sso_sessions')->where('sid', $sid)->where('revoked', false)->update(['last_seen' => time()]);
        }

        return $session;
    }

    public function establish(Request $request, Authenticatable $user): object
    {
        $sid = $request->session()->get('sso.sid');
        if (is_string($sid) && ($session = $this->valid($sid, true))
            && $session->subject === (string) $user->getAuthIdentifier()) {
            return $session;
        }
        $sid = bin2hex(random_bytes(32));
        $now = time();
        DB::table('sso_sessions')->insert([
            'sid' => $sid, 'subject' => (string) $user->getAuthIdentifier(),
            'auth_time' => $request->session()->get('sso.auth_time', $now),
            'last_seen' => $now, 'expires_at' => $now + config('sso-server.session_max'), 'revoked' => false,
        ]);
        $request->session()->put('sso.sid', $sid);

        return DB::table('sso_sessions')->where('sid', $sid)->first();
    }

    public function revoke(string $sid): void
    {
        DB::transaction(function () use ($sid): void {
            $session = DB::table('sso_sessions')->where('sid', $sid)->lockForUpdate()->first();
            if (! $session) {
                return;
            }
            DB::table('sso_sessions')->where('sid', $sid)->update(['revoked' => true]);
            // Current locking read also sees bindings committed while a MySQL snapshot
            // was waiting for the session lock. Keep the same lock order as exchange.
            $bindings = DB::table('sso_token_sessions')->where('sid', $sid)->orderBy('token_id')->lockForUpdate()->get();
            $clients = $bindings->pluck('client_id')->unique();
            foreach ($clients as $clientId) {
                if (DB::table('sso_clients')->where('client_id', $clientId)->whereNotNull('backchannel_logout_uri')->exists()) {
                    DB::table('sso_logout_deliveries')->insertOrIgnore([
                        'id' => bin2hex(random_bytes(32)), 'sid' => $sid, 'client_id' => $clientId, 'next_attempt' => time(),
                    ]);
                }
            }
            $ids = $bindings->pluck('token_id');
            DB::table('oauth_access_tokens')->whereIn('id', $ids)->update(['revoked' => true]);
        }, 5);
    }

    public function revokeAll(string $subject): void
    {
        foreach (DB::table('sso_sessions')->where('subject', $subject)->pluck('sid') as $sid) {
            $this->revoke($sid);
        }
    }
}
