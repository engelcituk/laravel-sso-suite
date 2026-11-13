<?php

declare(strict_types=1);

namespace LaravelSso\Server\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use LaravelSso\Server\GlobalSessions;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ServerRequestInterface;

final class ResourceController
{
    public function userinfo(ServerRequestInterface $request, ResourceServer $server, GlobalSessions $sessions): JsonResponse
    {
        $validated = $this->validate($server, $request);
        $scopes = $validated->getAttribute('oauth_scopes', []);
        abort_unless(in_array('openid', $scopes, true), 403);
        $binding = DB::table('sso_token_sessions')->where('token_id', $validated->getAttribute('oauth_access_token_id'))->first();
        $session = $binding ? $sessions->valid($binding->sid, true) : null;
        abort_unless($session !== null, 401);
        $user = $sessions->activeUser($session->subject);
        $claims = ['sub' => $session->subject];
        if (in_array('profile', $scopes, true)) {
            $claims['name'] = $user->getAttribute('name');
        }
        if (in_array('email', $scopes, true)) {
            $claims['email'] = $user->getAttribute('email');
            $claims['email_verified'] = $user->getAttribute('email_verified_at') !== null;
        }

        return response()->json($claims)->header('Cache-Control', 'no-store');
    }

    // Private extension, deliberately not advertised as an OIDC standard endpoint.
    public function status(ServerRequestInterface $request, ResourceServer $server, GlobalSessions $sessions): JsonResponse
    {
        $validated = $this->validate($server, $request);
        $binding = DB::table('sso_token_sessions')->where('token_id', $validated->getAttribute('oauth_access_token_id'))->first();
        $session = $binding ? $sessions->valid($binding->sid, true) : null;

        return response()->json(['active' => $session !== null, 'sid' => $session?->sid, 'sub' => $session?->subject])
            ->header('Cache-Control', 'no-store');
    }

    private function validate(ResourceServer $server, ServerRequestInterface $request): ServerRequestInterface
    {
        try {
            return $server->validateAuthenticatedRequest($request);
        } catch (OAuthServerException) {
            abort(401, 'Invalid bearer token.', ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
        }
    }
}
