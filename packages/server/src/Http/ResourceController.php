<?php

declare(strict_types=1);

namespace LaravelSso\Server\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use LaravelSso\Server\ClientUsers;
use LaravelSso\Server\GlobalSessions;
use LaravelSso\Server\Protocol\BearerFailure;
use LaravelSso\Server\Protocol\ClaimsRequest;
use LaravelSso\Server\Protocol\Parameters;
use LaravelSso\Server\UserClaims;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ServerRequestInterface;

final class ResourceController
{
    public function userinfo(ServerRequestInterface $request, ResourceServer $server, GlobalSessions $sessions): JsonResponse
    {
        $validated = $this->validate($server, $request);
        $scopes = $validated->getAttribute('oauth_scopes', []);
        if (! in_array('openid', $scopes, true)) {
            throw new BearerFailure('insufficient_scope', 403);
        }
        $binding = DB::table('sso_token_sessions')->where('token_id', $validated->getAttribute('oauth_access_token_id'))->first();
        $session = $binding ? $sessions->valid($binding->sid, true) : null;
        if ($session === null || ! app(ClientUsers::class)->allows($binding->client_id, $session->subject)) {
            throw new BearerFailure;
        }
        $user = $sessions->activeUser($session->subject);
        if ($user === null) {
            throw new BearerFailure;
        }
        $selection = json_decode($binding->claims_request ?? '[]', true, 8, JSON_THROW_ON_ERROR);
        $attributes = app(UserClaims::class);
        $claims = ['sub' => $session->subject] + $attributes->scoped($user, $scopes)
            + app(ClaimsRequest::class)->select($selection, 'userinfo', $attributes->available($user));
        $claims = app(ClaimsRequest::class)->constrain($selection, 'userinfo', $claims);

        return response()->json($claims)->header('Cache-Control', 'no-store');
    }

    // Private extension, deliberately not advertised as an OIDC standard endpoint.
    public function status(ServerRequestInterface $request, ResourceServer $server, GlobalSessions $sessions): JsonResponse
    {
        $validated = $this->validate($server, $request);
        $binding = DB::table('sso_token_sessions')->where('token_id', $validated->getAttribute('oauth_access_token_id'))->first();
        $session = $binding ? $sessions->valid($binding->sid, true) : null;

        if ($session && ! app(ClientUsers::class)->allows($binding->client_id, $session->subject)) {
            $session = null;
        }

        return response()->json(['active' => $session !== null, 'sid' => $session?->sid, 'sub' => $session?->subject])
            ->header('Cache-Control', 'no-store');
    }

    private function validate(ResourceServer $server, ServerRequestInterface $request): ServerRequestInterface
    {
        if (array_key_exists('access_token', $request->getQueryParams())) {
            throw new BearerFailure('invalid_request', 400); // Query tokens leak into browser/proxy logs.
        }
        $type = strtolower(explode(';', $request->getHeaderLine('Content-Type'))[0]);
        if ($request->getMethod() === 'POST' && $type === 'application/x-www-form-urlencoded') {
            $body = app(Parameters::class)->read(request());
            if (isset($body['access_token'])) {
                if ($request->hasHeader('Authorization') || strlen($body['access_token']) > 16384
                    || preg_match('/^[A-Za-z0-9\-._~+\/]+=*$/D', $body['access_token']) !== 1) {
                    throw new BearerFailure('invalid_request', 400);
                }
                $request = $request->withHeader('Authorization', 'Bearer '.$body['access_token']);
            }
        }
        try {
            return $server->validateAuthenticatedRequest($request);
        } catch (OAuthServerException) {
            throw new BearerFailure;
        }
    }
}
