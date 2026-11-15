<?php

declare(strict_types=1);

namespace LaravelSso\Client\Http;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LaravelSso\Client\Audit;
use LaravelSso\Client\Contracts\ProvisionsUsers;
use LaravelSso\Client\Exceptions\ProtocolException;
use LaravelSso\Client\IdentityLinks;
use LaravelSso\Client\IdTokenValidator;
use LaravelSso\Client\Provider;
use LaravelSso\Client\TrustedHttp;
use LaravelSso\Contracts\Identity;

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
        try {
            return $this->completeCallback($request, $provider, $http, $validator, $users);
        } catch (ProtocolException $error) {
            app(Audit::class)->record('login.rejected');
            throw $error;
        }
    }

    private function completeCallback(Request $request, Provider $provider, TrustedHttp $http,
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
        try {
            $userinfo = $http->request($metadata['userinfo_endpoint'])->withToken($response->json('access_token'))->get($metadata['userinfo_endpoint']);
        } catch (\Throwable $e) {
            throw new ProtocolException('OIDC UserInfo unavailable.', 0, $e);
        }
        if (! $userinfo->successful() || ! is_array($userinfo->json()) || $userinfo->json('sub') !== $identity->subject) {
            throw new ProtocolException('OIDC UserInfo subject mismatch or invalid response.');
        }
        $identity = new Identity($identity->issuer, $identity->subject, $identity->sessionId, $identity->authenticatedAt,
            array_intersect_key($userinfo->json(), array_flip(['name', 'email', 'email_verified'])));
        $user = $users->provision($identity);
        if (! $user instanceof Model) {
            throw new ProtocolException('A local Eloquent user is required.');
        }
        app(IdentityLinks::class)->link($user, $identity->issuer, $identity->subject);
        /** @var StatefulGuard $guard */
        $guard = Auth::guard(config('sso-client.guard'));
        $guard->login($user, false);
        $request->session()->regenerate();
        $lifetime = max(1, (int) config('sso-client.local_session_ttl', 300));
        if (is_int($response->json('expires_in')) && $response->json('expires_in') >= 0) {
            $lifetime = min($lifetime, $response->json('expires_in'));
        }
        DB::table('sso_local_sessions')->insert([
            'session_hash' => hash('sha256', $request->session()->getId()),
            'user_type' => $user::class, 'user_id' => (string) $user->getAuthIdentifier(),
            'issuer' => $identity->issuer, 'subject' => $identity->subject, 'sid' => $identity->sessionId, 'revoked' => false,
            'expires_at' => time() + $lifetime,
        ]);
        $request->session()->put('sso.id_token', $response->json('id_token'));
        $request->session()->put('sso.auth_mode', 'sso');
        $request->session()->put('sso.identity', [
            'iss' => $identity->issuer, 'sub' => $identity->subject, 'sid' => $identity->sessionId,
            'access_token' => $response->json('access_token'), 'checked_at' => 0,
        ]);
        app(Audit::class)->record('login.completed', ['issuer' => $identity->issuer, 'subject' => $identity->subject, 'sid' => $identity->sessionId]);

        return redirect(config('sso-client.after_login', '/'))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
