<?php

declare(strict_types=1);

namespace LaravelSso\Server\Http;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LaravelSso\Server\GlobalSessions;
use LaravelSso\Server\SigningKeys;
use Symfony\Component\HttpFoundation\Response;

final class EndSessionController
{
    public function confirm(Request $request, SigningKeys $keys): Response
    {
        $input = $request->validate(['id_token_hint' => 'required|string|max:16384',
            'post_logout_redirect_uri' => 'sometimes|url', 'state' => 'sometimes|string|max:512']);
        try {
            try {
                $claims = (array) JWT::decode($input['id_token_hint'], JWK::parseKeySet($keys->jwks()));
            } catch (ExpiredException $e) {
                // firebase validates the signature BEFORE expiry. Only RP logout hints may be expired.
                $claims = (array) $e->getPayload();
            }
        } catch (\Throwable) {
            abort(400, 'Invalid logout hint.');
        }
        $sid = $claims['sid'] ?? null;
        $aud = $claims['aud'] ?? null;
        abort_unless(is_string($sid) && is_string($aud) && ($claims['iss'] ?? null) === config('sso-server.issuer')
            && ! isset($claims['events']) && is_string($claims['sub'] ?? null), 400);
        $session = DB::table('sso_sessions')->where('sid', $sid)->first();
        $client = DB::table('sso_clients')->where('client_id', $aud)->first();
        abort_unless($session && $client && $session->subject === $claims['sub']
            && $request->session()->get('sso.sid') === $sid
            && DB::table('sso_token_sessions')->where('sid', $sid)->where('client_id', $aud)->exists(), 400);
        $redirect = $input['post_logout_redirect_uri'] ?? null;
        abort_unless($redirect === null || in_array($redirect, json_decode($client->post_logout_redirect_uris ?? '[]', true), true), 400);
        $transaction = bin2hex(random_bytes(32));
        $request->session()->put('sso.logout', ['transaction' => $transaction, 'sid' => $sid,
            'redirect' => $redirect, 'state' => $input['state'] ?? null, 'created' => time()]);

        return response()->view('sso-server::logout', compact('transaction'))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function logout(Request $request, GlobalSessions $sessions): Response
    {
        $input = $request->validate(['transaction' => 'required|string|size:64']);
        $pending = $request->session()->pull('sso.logout');
        abort_unless(is_array($pending) && hash_equals($pending['transaction'], $input['transaction'])
            && time() - $pending['created'] < 300 && $request->session()->get('sso.sid') === $pending['sid'], 400);
        $sessions->revoke($pending['sid']);
        /** @var StatefulGuard $guard */
        $guard = Auth::guard(config('sso-server.guard'));
        $guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if ($pending['redirect']) {
            $url = $pending['redirect'];
            if ($pending['state'] !== null) {
                $url .= (str_contains($url, '?') ? '&' : '?').http_build_query(['state' => $pending['state']]);
            }

            return redirect()->away($url);
        }

        return response('Sesión global cerrada.')->header('Cache-Control', 'no-store');
    }
}
