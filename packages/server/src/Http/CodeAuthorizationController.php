<?php

declare(strict_types=1);

namespace LaravelSso\Server\Http;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Bridge\User;
use Laravel\Passport\Passport;
use LaravelSso\Server\GlobalSessions;
use LaravelSso\Server\OidcServer;
use LaravelSso\Server\Protocol\AuthorizationInput;
use LaravelSso\Server\Protocol\AuthorizationResponse;
use LaravelSso\Server\Protocol\ClaimsRequest;
use LaravelSso\Server\Protocol\IdTokenHint;
use LaravelSso\Server\Protocol\InvalidRequest;
use LaravelSso\Server\Protocol\Parameters;
use LaravelSso\Server\Protocol\RedirectUri;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

final class CodeAuthorizationController
{
    public function authorize(Request $request, ServerRequestInterface $psr, Parameters $parameters,
        AuthorizationInput $rules, OidcServer $factory, GlobalSessions $sessions, ClaimsRequest $claims): Response
    {
        $input = $parameters->read($request);
        if (! Str::isUuid($input['client_id'] ?? '')) {
            return response()->json(['error' => 'invalid_request'], 400)->header('Cache-Control', 'no-store');
        }
        // Never redirect until BOTH registration and exact redirect URI are trusted.
        $client = Passport::client()->newQuery()->whereKey($input['client_id'])->where('revoked', false)->first();
        $registration = DB::table('sso_clients')->where('client_id', $input['client_id'])->first();
        $redirect = $input['redirect_uri'] ?? '';
        if (! $client || ! $registration || ! app(RedirectUri::class)->safe($redirect)
            || ! in_array($redirect, $client->getAttribute('redirect_uris'), true)) {
            return response()->json(['error' => 'invalid_request'], 400)->header('Cache-Control', 'no-store');
        }
        if (! in_array($input['response_mode'] ?? 'query', ['query', 'form_post'], true)) {
            return response('', 400)->header('Cache-Control', 'no-store');
        }
        if (! $client->hasGrantType('authorization_code')) {
            return $this->error($input, 'unauthorized_client');
        }
        if ($error = $rules->error($input, ! $client->confidential() || (bool) $registration->require_pkce)) {
            if ($error === 'request_not_supported') {
                $input['response_mode'] = $rules->unsupportedRequestErrorMode($input);
            }

            return $this->error($input, $error);
        }
        try {
            $selection = $claims->parse($input['claims'] ?? null);
            $authorization = $factory->make()->validateAuthorizationRequest($psr->withQueryParams($input));
        } catch (InvalidRequest) {
            return $this->error($input, 'invalid_request');
        } catch (OAuthServerException $e) {
            return $this->error($input, $e->getErrorType());
        }
        $expectedSubject = null;
        if (isset($input['id_token_hint'])) {
            try {
                $expectedSubject = app(IdTokenHint::class)->validate($input['id_token_hint'])['sub'];
            } catch (InvalidRequest) {
                return $this->error($input, 'invalid_request');
            }
        }
        $prompts = $rules->prompts($input);
        /** @var StatefulGuard $guard */
        $guard = Auth::guard(config('sso-server.guard'));
        $sid = $request->session()->get('sso.sid');
        $existing = is_string($sid) ? $sessions->valid($sid) : null;
        $fingerprint = $this->fingerprint($input);
        $challenge = $request->session()->get('sso.reauthentication');
        $freshLogin = is_array($challenge) && $challenge['fingerprint'] === $fingerprint
            && time() - $challenge['created'] <= 300 && $request->session()->has('sso.login_epoch')
            && $request->session()->get('sso.login_epoch') !== $challenge['epoch'];
        if ($freshLogin) {
            $request->session()->forget('sso.reauthentication');
        }
        $authTime = $existing->auth_time ?? $request->session()->get('sso.auth_time');
        $freshRequired = ($sid !== null && ! $existing)
            || (! $freshLogin && in_array('login', $prompts, true))
            || (! $freshLogin && isset($input['max_age']) && ($input['max_age'] === '0'
                || ! is_int($authTime) || time() - $authTime > (int) $input['max_age']))
            || ($expectedSubject !== null && $guard->user() && (string) $guard->user()->getAuthIdentifier() !== $expectedSubject);
        if ($guard->guest() || $freshRequired) {
            if (in_array('none', $prompts, true) || ($freshLogin && $expectedSubject !== null)) {
                return $this->error($input, 'login_required');
            }

            return $this->login($request, $input, $guard);
        }
        $user = $guard->user();
        if (! $claims->permits($selection, (string) $user->getAuthIdentifier())) {
            return $this->error($input, in_array('none', $prompts, true) ? 'login_required' : 'access_denied');
        }
        if (! $sessions->activeUser((string) $user->getAuthIdentifier())) {
            return $this->error($input, 'access_denied');
        }
        $session = $sessions->establish($request, $user);
        if (isset($input['max_age'], $selection['id_token']['auth_time'])
            && ! $claims->matches($selection['id_token']['auth_time'], (int) $session->auth_time)) {
            return $this->error($input, 'access_denied');
        }
        $authorization->setUser(new User((string) $user->getAuthIdentifier()));
        $context = ['nonce' => $input['nonce'] ?? null, 'sid' => $session->sid, 'claims' => $selection];
        $interaction = ! $registration->auto_approve || in_array('consent', $prompts, true) || in_array('select_account', $prompts, true);
        if (! $interaction) {
            return $this->complete($request, $authorization, $context, $input, $factory);
        }
        if (in_array('none', $prompts, true)) {
            return $this->error($input, 'consent_required');
        }
        $transaction = bin2hex(random_bytes(32));
        $request->session()->put('sso.consent.'.$transaction, [
            'authorization' => serialize($authorization), 'context' => $context, 'input' => $input, 'created' => time(),
        ]);

        return response()->view('sso-server::consent', ['transaction' => $transaction,
            'scopes' => explode(' ', $input['scope']), 'account' => $user->getAuthIdentifier(), 'clientName' => $client->getAttribute('name'),
            'requestedClaims' => array_merge(array_keys($selection['userinfo'] ?? []), array_keys($selection['id_token'] ?? [])),
            'selectAccount' => in_array('select_account', $prompts, true)])
            ->header('Cache-Control', 'no-store')->header('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'none'");
    }

    public function consent(Request $request, OidcServer $factory, GlobalSessions $sessions): Response
    {
        $input = $request->validate(['transaction' => 'required|string|size:64', 'approve' => 'required|in:0,1,switch']);
        $pending = $request->session()->pull('sso.consent.'.$input['transaction']);
        abort_unless(is_array($pending) && time() - $pending['created'] < 300, 400);
        $session = $sessions->valid($pending['context']['sid']);
        /** @var StatefulGuard $guard */
        $guard = Auth::guard(config('sso-server.guard'));
        $user = $guard->user();
        abort_unless($session && $user && $session->subject === (string) $user->getAuthIdentifier(), 403);
        $client = Passport::client()->newQuery()->whereKey($pending['input']['client_id'])->where('revoked', false)->first();
        if (! $client || ! DB::table('sso_clients')->where('client_id', $client->getKey())->exists()) {
            return $this->error($pending['input'], 'unauthorized_client');
        }
        if ($input['approve'] === 'switch') {
            return $this->login($request, $pending['input'], $guard);
        }
        if (! $input['approve']) {
            return $this->error($pending['input'], 'access_denied');
        }
        /** @var AuthorizationRequestInterface $authorization */
        $authorization = unserialize($pending['authorization']);

        return $this->complete($request, $authorization, $pending['context'], $pending['input'], $factory);
    }

    /** @param array{nonce: ?string, sid: string} $context
     * @param  array<string, string>  $input
     */
    private function complete(Request $request, AuthorizationRequestInterface $authorization, array $context, array $input, OidcServer $factory): Response
    {
        $request->attributes->set('sso.authorization', $context);
        $authorization->setAuthorizationApproved(true);
        $response = $factory->make()->completeAuthorizationRequest($authorization, new PsrResponse);
        parse_str(parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);
        $parameters = ['code' => $query['code']];
        if (isset($input['state'])) {
            $parameters['state'] = $input['state'];
        }

        return app(AuthorizationResponse::class)->send($input['redirect_uri'], $parameters, $input['response_mode'] ?? 'query');
    }

    /** @param array<string, string> $input */
    private function error(array $input, string $error): Response
    {
        $parameters = ['error' => $error];
        if (isset($input['state'])) {
            $parameters['state'] = $input['state'];
        }

        return app(AuthorizationResponse::class)->send($input['redirect_uri'], $parameters, $input['response_mode'] ?? 'query');
    }

    /** @param array<string, string> $input */
    private function login(Request $request, array $input, StatefulGuard $guard): Response
    {
        $epoch = $request->session()->get('sso.login_epoch');
        if ($guard->check()) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        $request->session()->put('sso.reauthentication', [
            'fingerprint' => $this->fingerprint($input), 'epoch' => $epoch, 'created' => time(),
        ]);
        $request->session()->put('url.intended', config('sso-server.issuer').'/oidc/authorize?'.http_build_query($input, '', '&', PHP_QUERY_RFC3986));

        return redirect()->route('login')->header('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $input */
    private function fingerprint(array $input): string
    {
        ksort($input);

        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }
}
