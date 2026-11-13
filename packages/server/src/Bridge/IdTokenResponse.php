<?php

declare(strict_types=1);

namespace LaravelSso\Server\Bridge;

use Illuminate\Support\Facades\DB;
use LaravelSso\Server\GlobalSessions;
use LaravelSso\Server\SigningKeys;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;

final class IdTokenResponse extends BearerTokenResponse
{
    protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
    {
        $context = request()->attributes->get('sso.token_context');
        $sessions = app(GlobalSessions::class);
        $session = $context ? $sessions->valid($context->sid) : null;
        if (! $session || $session->subject !== $accessToken->getUserIdentifier()) {
            throw OAuthServerException::invalidGrant('Missing or invalid OIDC session binding.');
        }
        $scopes = array_map(static fn ($scope) => $scope->getIdentifier(), $accessToken->getScopes());
        if (! in_array('openid', $scopes, true)) {
            throw OAuthServerException::invalidScope('openid');
        }
        $claims = [
            'iss' => config('sso-server.issuer'), 'sub' => $session->subject,
            'aud' => $accessToken->getClient()->getIdentifier(), 'sid' => $session->sid,
            'auth_time' => (int) $session->auth_time, 'iat' => time(),
            'exp' => time() + config('sso-server.id_token_ttl'), 'nonce' => $context->nonce,
        ];
        $user = $sessions->activeUser($session->subject);
        if (in_array('profile', $scopes, true)) {
            $claims['name'] = $user->getAttribute('name');
        }
        if (in_array('email', $scopes, true)) {
            $claims['email'] = $user->getAttribute('email');
            $claims['email_verified'] = $user->getAttribute('email_verified_at') !== null;
        }
        DB::table('sso_token_sessions')->insert([
            'token_id' => $accessToken->getIdentifier(), 'sid' => $session->sid,
            'client_id' => $accessToken->getClient()->getIdentifier(),
        ]);

        return ['id_token' => app(SigningKeys::class)->sign($claims)];
    }
}
