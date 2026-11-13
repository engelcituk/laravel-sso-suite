<?php

declare(strict_types=1);

namespace LaravelSso\Server\Bridge;

use Firebase\JWT\JWT;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\DB;
use LaravelSso\Server\GlobalSessions;
use LaravelSso\Server\Protocol\ClaimsRequest;
use LaravelSso\Server\SigningKeys;
use LaravelSso\Server\UserClaims;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResponseTypes\AbstractResponseType;
use Psr\Http\Message\ResponseInterface;

final class IdTokenResponse extends AbstractResponseType
{
    public function generateHttpResponse(ResponseInterface $response): ResponseInterface
    {
        // League's entity includes new timestamps on each serialization. Hash exactly once.
        $encoded = $this->accessToken->toString();
        $body = ['token_type' => 'Bearer', 'expires_in' => max(0, $this->accessToken->getExpiryDateTime()->getTimestamp() - time()),
            'access_token' => $encoded, 'id_token' => $this->idToken($this->accessToken, $encoded)];

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache')
            ->withBody(Utils::streamFor(json_encode($body, JSON_THROW_ON_ERROR)));
    }

    private function idToken(AccessTokenEntityInterface $accessToken, string $encoded): string
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
            'acr' => ClaimsRequest::ACR,
            'exp' => time() + config('sso-server.id_token_ttl'),
        ];
        if ($context->nonce !== null && $context->nonce !== '') {
            $claims['nonce'] = $context->nonce;
        }
        $claims['at_hash'] = JWT::urlsafeB64Encode(substr(hash('sha256', $encoded, true), 0, 16));
        $selection = json_decode($context->claims_request ?? '[]', true, 8, JSON_THROW_ON_ERROR);
        $user = $sessions->activeUser($session->subject);
        if ($user === null) {
            throw OAuthServerException::invalidGrant('The authenticated identity is no longer active.');
        }
        $available = app(UserClaims::class)->available($user);
        $claims += app(ClaimsRequest::class)->select($selection, 'id_token', $available);
        $claims = app(ClaimsRequest::class)->constrain($selection, 'id_token', $claims);
        // Code flow returns scope-requested profile/email claims through UserInfo
        // (OIDC Core 5.4), keeping this token focused on authentication.
        DB::table('sso_token_sessions')->insert([
            'token_id' => $accessToken->getIdentifier(), 'sid' => $session->sid,
            'client_id' => $accessToken->getClient()->getIdentifier(),
            'code_id' => $context->code_id,
            'claims_request' => $context->claims_request,
        ]);

        return app(SigningKeys::class)->sign($claims);
    }
}
