<?php

declare(strict_types=1);

namespace LaravelSso\Server\Http;

use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LaravelSso\Server\GlobalSessions;
use LaravelSso\Server\Protocol\IdTokenHint;
use LaravelSso\Server\Protocol\InvalidRequest;
use LaravelSso\Server\Protocol\Parameters;
use LaravelSso\Server\Protocol\RedirectUri;
use Symfony\Component\HttpFoundation\Response;

final class LogoutEndpointController
{
    public function confirm(Request $request, Parameters $parameters, IdTokenHint $hints): Response
    {
        $input = $parameters->read($request);
        if (isset($input['transaction']) || strlen($input['state'] ?? '') > 512 || strlen($input['id_token_hint'] ?? '') > 16384) {
            throw new InvalidRequest;
        }
        $claims = isset($input['id_token_hint']) ? $hints->validate($input['id_token_hint']) : null;
        $clientId = $input['client_id'] ?? null;
        if ($claims !== null) {
            $aud = is_array($claims['aud']) ? $claims['aud'] : [$claims['aud']];
            $clientId ??= $claims['azp'] ?? (count($aud) === 1 ? $aud[0] : null);
            if (! is_string($clientId) || ! in_array($clientId, $aud, true)) {
                throw new InvalidRequest;
            }
        }
        if ($clientId !== null && ! Str::isUuid($clientId)) {
            throw new InvalidRequest;
        }
        $client = $clientId !== null ? DB::table('sso_clients')->where('client_id', $clientId)->first() : null;
        if ($clientId !== null && ! $client) {
            throw new InvalidRequest;
        }
        $redirect = $input['post_logout_redirect_uri'] ?? null;
        if ($redirect !== null && (! $client || ! app(RedirectUri::class)->safe($redirect)
            || ! in_array($redirect, json_decode($client->post_logout_redirect_uris ?? '[]', true), true))) {
            throw new InvalidRequest;
        }
        /** @var StatefulGuard $guard */
        $guard = Auth::guard(config('sso-server.guard'));
        $browserSid = $request->session()->get('sso.sid');
        $current = is_string($browserSid) ? DB::table('sso_sessions')->where('sid', $browserSid)->first() : null;
        if ($current && (! $guard->user() || $current->subject !== (string) $guard->user()->getAuthIdentifier())) {
            $current = null;
        }
        $hinted = null;
        if ($claims !== null) {
            $query = DB::table('sso_sessions')->where('subject', $claims['sub'])
                ->where('expires_at', '>=', time() - config('sso-server.logout_hint_retention'))
                ->whereIn('sid', DB::table('sso_token_sessions')->where('client_id', $clientId)->select('sid'));
            if (isset($claims['sid'])) {
                $query->where('sid', $claims['sid']);
            } elseif ($current && $current->subject === $claims['sub']) {
                $query->where('sid', $current->sid);
            }
            $hinted = $query->orderByDesc('expires_at')->first();
            if (! $hinted) {
                throw new InvalidRequest;
            }
        }
        // A hint never silently logs out another browser user. Every path asks for consent.
        $target = $guard->check() ? $current : ($current ?? $hinted);
        $transaction = bin2hex(random_bytes(32));
        $request->session()->put('sso.logout', ['transaction' => $transaction, 'sid' => $target?->sid,
            'browser_sid' => $browserSid, 'redirect' => $redirect, 'state' => $input['state'] ?? null, 'created' => time()]);

        $data = ['transaction' => $transaction, 'clientName' => $clientId, 'account' => $target?->subject];
        if ($request->expectsJson()) {
            return response()->json(['interaction' => 'logout', 'csrf_token' => $request->session()->token()] + $data)->header('Cache-Control', 'no-store');
        }

        return response()->view('sso-server::logout', $data)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'none'");
    }

    public function logout(Request $request, GlobalSessions $sessions): Response
    {
        $transaction = $request->input('transaction');
        $pending = $request->session()->pull('sso.logout');
        if (! is_string($transaction) || ! is_array($pending) || ! hash_equals($pending['transaction'], $transaction)
            || time() - $pending['created'] >= 300 || $request->session()->get('sso.sid') !== $pending['browser_sid']) {
            throw new InvalidRequest;
        }
        if ($request->input('approve', '1') === '0') {
            return ($request->expectsJson() ? response()->json(['signed_out' => false, 'cancelled' => true]) : response('Cierre de sesión cancelado.'))->header('Cache-Control', 'no-store');
        }
        if ($pending['sid'] !== null) {
            $sessions->revoke($pending['sid']);
        }
        /** @var StatefulGuard $guard */
        $guard = Auth::guard(config('sso-server.guard'));
        if (! $guard instanceof SessionGuard) {
            throw new \RuntimeException('Central logout requires Laravel SessionGuard.');
        }
        $guard->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if ($pending['redirect'] !== null) {
            $url = $pending['redirect'];
            if ($pending['state'] !== null) {
                $url .= (str_contains($url, '?') ? '&' : '?').http_build_query(['state' => $pending['state']], '', '&', PHP_QUERY_RFC3986);
            }

            return ($request->expectsJson() ? response()->json(['signed_out' => true, 'redirect_to' => $url]) : redirect()->away($url))->header('Cache-Control', 'no-store');
        }

        return ($request->expectsJson() ? response()->json(['signed_out' => true]) : response('Sesión global cerrada.'))->header('Cache-Control', 'no-store');
    }
}
