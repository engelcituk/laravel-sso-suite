<?php

declare(strict_types=1);

namespace LaravelSso\Server;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class LogoutDeliveries
{
    public function deliverOne(): bool
    {
        $delivery = DB::transaction(function () {
            $row = DB::table('sso_logout_deliveries')->whereNull('delivered_at')->where('next_attempt', '<=', time())
                ->orderBy('next_attempt')->lockForUpdate()->first();
            if ($row) {
                DB::table('sso_logout_deliveries')->where('id', $row->id)->update([
                    'attempts' => $row->attempts + 1, 'next_attempt' => time() + 60,
                ]);
            }

            return $row;
        });
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
                $success = Http::asForm()->timeout(config('sso-server.backchannel_timeout'))
                    ->connectTimeout(config('sso-server.backchannel_timeout'))->withoutRedirecting()
                    ->post($url, ['logout_token' => $token])->successful();
            } catch (\Throwable) {
                $success = false;
            }
        }
        DB::table('sso_logout_deliveries')->where('id', $delivery->id)->update([
            'delivered_at' => $success ? time() : null,
            'next_attempt' => time() + min(3600, 2 ** min(10, $delivery->attempts + 1) * 5),
        ]);

        return true;
    }
}
