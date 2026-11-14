<?php

declare(strict_types=1);

namespace LaravelSso\Server;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class LogoutDeliveries
{
    public function deliverOne(): bool
    {
        $timeout = max(1, (int) config('sso-server.backchannel_timeout'));
        $delivery = DB::transaction(function () use ($timeout) {
            $query = DB::table('sso_logout_deliveries')->whereNull('delivered_at')->where('next_attempt', '<=', time())
                ->orderBy('next_attempt')->orderBy('id');
            $row = $query->lock(DB::getDriverName() === 'sqlite' ? true : 'for update skip locked')->first();
            if ($row) {
                DB::table('sso_logout_deliveries')->where('id', $row->id)->update([
                    'attempts' => $row->attempts + 1, 'next_attempt' => time() + max(60, $timeout + 5),
                ]);
            }

            return $row;
        }, 5);
        if (! $delivery) {
            return false;
        }
        $client = DB::table('sso_clients')->where('client_id', $delivery->client_id)->first();
        $session = DB::table('sso_sessions')->where('sid', $delivery->sid)->first();
        $url = $client?->backchannel_logout_uri;
        $parts = is_string($url) ? parse_url($url) : false;
        $schemes = config('sso-server.allow_http') && ! app()->environment('production') ? ['http', 'https'] : ['https'];
        $trusted = $parts && in_array($url, config('sso-server.backchannel_allowed_urls'), true)
            && in_array($parts['scheme'] ?? '', $schemes, true) && ! isset($parts['user'])
            && ! isset($parts['pass']) && ! isset($parts['fragment']);
        $success = false;
        if ($trusted && $session) {
            $token = app(SigningKeys::class)->sign([
                'iss' => config('sso-server.issuer'), 'aud' => $delivery->client_id, 'sub' => $session->subject,
                'sid' => $session->sid, 'iat' => time(), 'exp' => time() + 120, 'jti' => $delivery->id,
                'events' => ['http://schemas.openid.net/event/backchannel-logout' => (object) []],
            ]);
            try {
                $success = Http::asForm()->timeout($timeout)
                    ->connectTimeout($timeout)->withoutRedirecting()
                    ->post($url, ['logout_token' => $token])->successful();
            } catch (\Throwable) {
                $success = false;
            }
        }
        // attempts is the lease generation. A late owner cannot overwrite a newer
        // attempt after its reservation has expired or undo a successful delivery.
        $updated = DB::table('sso_logout_deliveries')->where('id', $delivery->id)->whereNull('delivered_at')
            ->where('attempts', $delivery->attempts + 1)->update([
                'delivered_at' => $success ? time() : null,
                'next_attempt' => time() + min(3600, 2 ** min(10, $delivery->attempts + 1) * 5),
            ]);
        if ($updated === 1) {
            app(Audit::class)->record($success ? 'logout.delivered' : 'logout.retry',
                ['delivery' => $delivery->id, 'sid' => $delivery->sid, 'client' => $delivery->client_id], ['attempt' => (int) $delivery->attempts + 1]);
        }

        return true;
    }
}
