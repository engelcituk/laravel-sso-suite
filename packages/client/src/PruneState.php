<?php

declare(strict_types=1);

namespace LaravelSso\Client;

use Illuminate\Support\Facades\DB;

final class PruneState
{
    /** @return array{sessions: int, receipts: int} */
    public function prune(int $limit = 1000): array
    {
        $limit = max(1, min(10000, $limit));
        $window = max((int) config('sso-client.receipt_retention', 86400),
            max(0, (int) config('sso-client.max_token_age')) + 2 * max(0, (int) config('sso-client.clock_skew')) + 1);

        return DB::transaction(function () use ($limit, $window): array {
            $receipts = DB::table('sso_logout_receipts')->where('received_at', '<', time() - $window)
                ->orderBy('token_hash')->limit($limit)->lockForUpdate()->pluck('token_hash');
            $sessions = DB::table('sso_local_sessions')->where(fn ($query) => $query->where('revoked', true)
                ->orWhere('expires_at', '<=', time()))->orderBy('session_hash')->limit($limit)->lockForUpdate()->pluck('session_hash');

            return ['sessions' => DB::table('sso_local_sessions')->whereIn('session_hash', $sessions)->delete(),
                'receipts' => DB::table('sso_logout_receipts')->whereIn('token_hash', $receipts)->delete()];
        }, 5);
    }
}
