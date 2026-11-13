<?php

declare(strict_types=1);

namespace LaravelSso\Client\Http;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LaravelSso\Client\Contracts\ProvisionsUsers;
use LaravelSso\Client\Exceptions\ProtocolException;
use LaravelSso\Client\IdTokenValidator;
use LaravelSso\Client\Provider;
use LaravelSso\Client\TrustedHttp;

final class LoginController
{
    public function redirect(Request $request, Provider $provider): RedirectResponse
    {
        $metadata = $provider->metadata();
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        $maxAge = config('sso-client.max_age');
        $request->session()->put('sso.transaction', compact('state', 'nonce', 'verifier', 'maxAge') + ['created' => time()]);
        $query = http_build_query([
            'response_type' => 'code', 'client_id' => config('sso-client.client_id'),
            'redirect_uri' => config('sso-client.redirect_uri'), 'scope' => implode(' ', config('sso-client.scopes')),
            'state' => $state, 'nonce' => $nonce, 'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        ], '', '&', PHP_QUERY_RFC3986);
        if ($maxAge !== null) {
            $query .= '&max_age='.(int) $maxAge;
        }

        return redirect()->away($metadata['authorization_endpoint'].'?'.$query);
    }

    public function callback(Request $request, Provider $provider, TrustedHttp $http,
        IdTokenValidator $validator, ProvisionsUsers $users): RedirectResponse
    {
        $transaction = $request->session()->pull('sso.transaction');
        if (! is_array($transaction) || ! is_string($request->query('state'))
            || ! hash_equals($transaction['state'], $request->query('state'))
            || time() - $transaction['created'] > config('sso-client.transaction_ttl')
            || ! is_string($request->query('code')) || $request->query('code') === '') {
            throw new ProtocolException('Invalid or expired authorization transaction.');
        }
        $metadata = $provider->metadata();
        $issuer = $request->query('iss');
        if (($issuer !== null && $issuer !== config('sso-client.issuer'))
            || (($metadata['authorization_response_iss_parameter_supported'] ?? false) && $issuer === null)) {
            throw new ProtocolException('Authorization response issuer mismatch.');
        }
        try {
            $response = $http->request($metadata['token_endpoint'])->asForm()->post($metadata['token_endpoint'], [
                'grant_type' => 'authorization_code', 'client_id' => config('sso-client.client_id'),
                'client_secret' => config('sso-client.client_secret'), 'redirect_uri' => config('sso-client.redirect_uri'),
                'code' => $request->query('code'), 'code_verifier' => $transaction['verifier'],
            ]);
        } catch (\Throwable $e) {
            throw new ProtocolException('OIDC code exchange unavailable.', 0, $e);
        }
        if (! $response->successful() || ! is_string($response->json('id_token'))
            || ! is_string($response->json('access_token'))) {
            throw new ProtocolException('OIDC code exchange failed.');
        }
        $identity = $validator->validate($response->json('id_token'), $http->get($metadata['jwks_uri']), $transaction['nonce'],
            $transaction['maxAge'] ?? null, $response->json('access_token'));
        $user = $users->provision($identity);
        /** @var StatefulGuard $guard */
        $guard = Auth::guard(config('sso-client.guard'));
        $guard->login($user, false);
        $request->session()->regenerate();
        DB::table('sso_local_sessions')->insert([
            'session_hash' => hash('sha256', $request->session()->getId()),
            'issuer' => $identity->issuer, 'subject' => $identity->subject, 'sid' => $identity->sessionId, 'revoked' => false,
        ]);
        $request->session()->put('sso.id_token', $response->json('id_token'));
        $request->session()->put('sso.identity', [
            'iss' => $identity->issuer, 'sub' => $identity->subject, 'sid' => $identity->sessionId,
            'access_token' => $response->json('access_token'), 'checked_at' => 0,
        ]);

        return redirect(config('sso-client.after_login', '/'))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
