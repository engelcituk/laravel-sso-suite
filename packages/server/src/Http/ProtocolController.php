<?php

declare(strict_types=1);

namespace LaravelSso\Server\Http;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LaravelSso\Server\OidcServer;
use LaravelSso\Server\SigningKeys;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Component\HttpFoundation\Response;

final class ProtocolController
{
    public function discovery(): JsonResponse
    {
        $issuer = config('sso-server.issuer');

        return response()->json([
            'issuer' => $issuer, 'authorization_endpoint' => $issuer.'/oidc/authorize',
            'token_endpoint' => $issuer.'/oidc/token', 'userinfo_endpoint' => $issuer.'/oidc/userinfo',
            'jwks_uri' => $issuer.'/oidc/jwks', 'response_types_supported' => ['code'],
            'end_session_endpoint' => $issuer.'/oidc/logout',
            'backchannel_logout_supported' => true, 'backchannel_logout_session_supported' => true,
            'grant_types_supported' => ['authorization_code'], 'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'], 'scopes_supported' => ['openid', 'profile', 'email'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'none'],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => ['iss', 'sub', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'sid', 'name', 'email', 'email_verified'],
        ])->header('Cache-Control', 'public, max-age=300');
    }

    public function jwks(SigningKeys $keys): JsonResponse
    {
        return response()->json($keys->jwks())->header('Cache-Control', 'public, max-age=300');
    }

    public function token(Request $request, ServerRequestInterface $psr, OidcServer $server): Response
    {
        $request->attributes->remove('sso.token_context');
        if ($request->input('grant_type') !== 'authorization_code') {
            return response()->json(['error' => 'unsupported_grant_type'], 400)->header('Cache-Control', 'no-store');
        }
        try {
            $response = DB::transaction(fn () => $server->make()->respondToAccessTokenRequest($psr, new PsrResponse));
        } catch (OAuthServerException $e) {
            $response = $e->generateHttpResponse(new PsrResponse);
        }

        return (new HttpFoundationFactory)->createResponse($response);
    }
}
