<?php

declare(strict_types=1);

namespace LaravelSso\Server;

use Illuminate\Support\Facades\DB;

final class PruneState
{
    /** @return array<string, int> */
    public function prune(int $limit = 1000): array
    {
        $limit = max(1, min(10000, $limit));
        $cutoff = time() - max(3600, (int) config('sso-server.state_retention', 86400));
        $expired = gmdate('Y-m-d H:i:s', $cutoff);
        $counts = ['deliveries' => 0, 'contexts' => 0, 'codes' => 0, 'bindings' => 0, 'tokens' => 0, 'sessions' => 0,
            'remember_tokens' => 0, 'devices' => 0];
        $counts['deliveries'] = DB::transaction(function () use ($cutoff, $limit): int {
            $ids = DB::table('sso_logout_deliveries')->where('delivered_at', '<', $cutoff)
                ->orderBy('id')->limit($limit)->lockForUpdate()->pluck('id');

            return DB::table('sso_logout_deliveries')->whereIn('id', $ids)->delete();
        }, 5);
        // Only codes owned by this package; never purge host Passport grants or clients.
        $codes = DB::table('sso_auth_contexts')->whereIn('code_id', DB::table('oauth_auth_codes')
            ->where('expires_at', '<', $expired)->select('id'))->orderBy('code_id')->limit($limit)->pluck('code_id');
        foreach ($codes as $code) {
            $deleted = DB::transaction(function () use ($code, $expired): array {
                $row = DB::table('oauth_auth_codes')->where('id', $code)->where('expires_at', '<', $expired)->lockForUpdate()->first();
                if ($row) {
                    return ['contexts' => DB::table('sso_auth_contexts')->where('code_id', $code)->delete(),
                        'codes' => DB::table('oauth_auth_codes')->where('id', $code)->delete()];
                }

                return ['contexts' => 0, 'codes' => 0];
            }, 5);
            $counts['contexts'] += $deleted['contexts'];
            $counts['codes'] += $deleted['codes'];
        }
        $sessionCutoff = $cutoff - max(0, (int) config('sso-server.logout_hint_retention', 86400));
        $sids = DB::table('sso_sessions')->where('expires_at', '<', $sessionCutoff)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('sso_logout_deliveries')
                ->whereColumn('sso_logout_deliveries.sid', 'sso_sessions.sid'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('sso_token_sessions as binding')
                ->join('oauth_access_tokens as token', 'token.id', '=', 'binding.token_id')
                ->whereColumn('binding.sid', 'sso_sessions.sid')
                ->where(fn ($tokens) => $tokens->whereNull('token.expires_at')->orWhere('token.expires_at', '>=', $expired)))
            ->orderBy('sid')->limit($limit)->pluck('sid');
        foreach ($sids as $sid) {
            $deleted = DB::transaction(function () use ($sid, $sessionCutoff, $expired): array {
                $empty = ['tokens' => 0, 'bindings' => 0, 'sessions' => 0];
                $deviceId = DB::table('sso_sessions')->where('sid', $sid)->value('device_id');
                if (is_string($deviceId)) {
                    DB::table('sso_devices')->where('id', $deviceId)->lockForUpdate()->first();
                }
                // Same device -> session order as exchange/revocation.
                $session = DB::table('sso_sessions')->where('sid', $sid)->where('expires_at', '<', $sessionCutoff)->lockForUpdate()->first();
                if (! $session || DB::table('sso_logout_deliveries')->where('sid', $sid)->lockForUpdate()->first()) {
                    return $empty; // Pending deliveries are never discarded, regardless of age.
                }
                $bindings = DB::table('sso_token_sessions')->where('sid', $sid)->orderBy('token_id')->lockForUpdate()->get();
                $ids = $bindings->pluck('token_id');
                if (DB::table('oauth_access_tokens')->whereIn('id', $ids)
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>=', $expired))->exists()) {
                    return $empty;
                }

                return ['tokens' => DB::table('oauth_access_tokens')->whereIn('id', $ids)->delete(),
                    'bindings' => DB::table('sso_token_sessions')->whereIn('token_id', $ids)->delete(),
                    'sessions' => DB::table('sso_sessions')->where('sid', $sid)->delete()];
            }, 5);
            foreach ($deleted as $name => $count) {
                $counts[$name] += $count;
            }
        }
        $counts['remember_tokens'] = DB::transaction(function () use ($cutoff, $limit): int {
            $ids = DB::table('sso_remember_tokens')->where('expires_at', '<', $cutoff)->orderBy('id')->limit($limit)->lockForUpdate()->pluck('id');

            return DB::table('sso_remember_tokens')->whereIn('id', $ids)->delete();
        }, 5);
        $ids = DB::table('sso_devices')->where(fn ($query) => $query->where('expires_at', '<', $cutoff)->orWhere('revoked_at', '<', $cutoff))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('sso_sessions')->whereColumn('sso_sessions.device_id', 'sso_devices.id'))
            ->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $deleted = DB::transaction(function () use ($id, $cutoff): array {
                $device = DB::table('sso_devices')->where('id', $id)->lockForUpdate()->first();
                if (! $device || ($device->expires_at >= $cutoff && ($device->revoked_at === null || $device->revoked_at >= $cutoff))
                    || DB::table('sso_sessions')->where('device_id', $id)->lockForUpdate()->first()) {
                    return ['remember_tokens' => 0, 'devices' => 0];
                }

                return ['remember_tokens' => DB::table('sso_remember_tokens')->where('device_id', $id)->delete(),
                    'devices' => DB::table('sso_devices')->where('id', $id)->delete()];
            }, 5);
            foreach ($deleted as $name => $count) {
                $counts[$name] += $count;
            }
        }

        return $counts;
    }
}
