<?php

declare(strict_types=1);

namespace LaravelSso\Server\Http;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Bridge\User;
use LaravelSso\Server\GlobalSessions;
use LaravelSso\Server\OidcServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizationController
{
    public function authorize(Request $request, ServerRequestInterface $psr, OidcServer $factory, GlobalSessions $sessions): Response
    {
        try {
            $server = $factory->make();
            $authorization = $server->validateAuthorizationRequest($psr);
            $input = $request->validate([
                'redirect_uri' => 'required|string', 'nonce' => 'required|string|max:255',
                'state' => 'required|string|max:512', 'code_challenge_method' => 'required|in:S256',
                'code_challenge' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
                'max_age' => 'sometimes|integer|min:0', 'prompt' => 'sometimes|in:none,login,consent',
            ]);
            $scopes = array_map(static fn ($scope) => $scope->getIdentifier(), $authorization->getScopes());
            abort_unless(in_array('openid', $scopes, true) && array_diff($scopes, ['openid', 'profile', 'email']) === [], 400);
            $client = DB::table('sso_clients')->where('client_id', $authorization->getClient()->getIdentifier())->first();
            abort_unless($client !== null, 400, 'Client is not registered for OIDC.');
            /** @var StatefulGuard $guard */
            $guard = Auth::guard(config('sso-server.guard'));
            $sid = $request->session()->get('sso.sid');
            $existing = is_string($sid) ? $sessions->valid($sid) : null;
            // A revoked/expired global session cannot be silently resurrected by a web cookie.
            $freshRequired = ($sid !== null && ! $existing)
                || ($request->query('prompt') === 'login' && ! $request->session()->pull('sso.reauth', false))
                || ($existing && isset($input['max_age']) && time() - $existing->auth_time > (int) $input['max_age']);
            if ($guard->guest() || $freshRequired) {
                if ($request->query('prompt') === 'none') {
                    return $this->error($authorization, 'login_required');
                }
                if ($freshRequired) {
                    $guard->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                    $request->session()->put('sso.reauth', true);
                }

                return redirect()->guest(route('login'));
            }
            $user = $guard->user();
            abort_unless($sessions->activeUser((string) $user->getAuthIdentifier()) !== null, 403);
            $session = $sessions->establish($request, $user);
            $authorization->setUser(new User((string) $user->getAuthIdentifier()));
            $context = ['nonce' => $input['nonce'], 'sid' => $session->sid];
            if ($client->auto_approve && $request->query('prompt') !== 'consent') {
                return $this->complete($request, $authorization, $context, $factory);
            }
            if ($request->query('prompt') === 'none') {
                return $this->error($authorization, 'consent_required');
            }
            $transaction = bin2hex(random_bytes(32));
            $request->session()->put('sso.consent.'.$transaction, [
                'authorization' => serialize($authorization), 'context' => $context, 'created' => time(),
            ]);

            return response()->view('sso-server::consent', ['transaction' => $transaction, 'scopes' => $scopes]);
        } catch (OAuthServerException $e) {
            return (new HttpFoundationFactory)->createResponse($e->generateHttpResponse(new PsrResponse));
        }
    }

    public function consent(Request $request, OidcServer $factory, GlobalSessions $sessions): Response
    {
        $input = $request->validate(['transaction' => 'required|string|size:64', 'approve' => 'required|boolean']);
        $pending = $request->session()->pull('sso.consent.'.$input['transaction']);
        abort_unless(is_array($pending) && time() - $pending['created'] < 300, 400);
        $session = $sessions->valid($pending['context']['sid']);
        $user = Auth::guard(config('sso-server.guard'))->user();
        abort_unless($session && $user && $session->subject === (string) $user->getAuthIdentifier(), 403);
        /** @var AuthorizationRequestInterface $authorization */
        $authorization = unserialize($pending['authorization']);
        if (! $input['approve']) {
            return $this->error($authorization, 'access_denied');
        }

        return $this->complete($request, $authorization, $pending['context'], $factory);
    }

    /** @param array{nonce: string, sid: string} $context */
    private function complete(Request $request, AuthorizationRequestInterface $authorization, array $context, OidcServer $factory): Response
    {
        $request->attributes->set('sso.authorization', $context);
        $authorization->setAuthorizationApproved(true);
        $response = $factory->make()->completeAuthorizationRequest($authorization, new PsrResponse);

        return (new HttpFoundationFactory)->createResponse($response);
    }

    private function error(AuthorizationRequestInterface $authorization, string $error): Response
    {
        $uri = $authorization->getRedirectUri();

        return redirect()->away($uri.(str_contains($uri, '?') ? '&' : '?').http_build_query([
            'error' => $error, 'state' => $authorization->getState(),
        ]));
    }
}
