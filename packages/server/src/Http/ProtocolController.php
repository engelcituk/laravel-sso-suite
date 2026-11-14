<?php

declare(strict_types=1);

namespace LaravelSso\Server\Http;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use LaravelSso\Server\OidcServer;
use LaravelSso\Server\Protocol\ClaimsRequest;
use LaravelSso\Server\Protocol\Parameters;
use LaravelSso\Server\SigningKeys;
use LaravelSso\Server\UserClaims;
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
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'response_modes_supported' => ['query', 'form_post'],
            'authorization_response_iss_parameter_supported' => true,
            'claims_parameter_supported' => true, 'request_parameter_supported' => false,
            'acr_values_supported' => [ClaimsRequest::ACR],
            'request_uri_parameter_supported' => false, 'claim_types_supported' => ['normal'],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => array_merge(['iss', 'sub', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'sid', 'at_hash', 'acr'], app(UserClaims::class)->supported()),
        ])->header('Cache-Control', 'public, max-age=300');
    }

    public function jwks(SigningKeys $keys): JsonResponse
    {
        return response()->json($keys->jwks())->header('Cache-Control', 'public, max-age=300');
    }

    public function token(Request $request, ServerRequestInterface $psr, OidcServer $server, Parameters $parameters): Response
    {
        $request->attributes->remove('sso.token_context');
        $request->attributes->remove('sso.replayed_code');
        $request->attributes->remove('sso.authenticated_client');
        $body = $parameters->read($request);
        if (($body['grant_type'] ?? '') === '') {
            return response()->json(['error' => 'invalid_request'], 400)->header('Cache-Control', 'no-store');
        }
        if ($body['grant_type'] !== 'authorization_code') {
            return response()->json(['error' => 'unsupported_grant_type'], 400)->header('Cache-Control', 'no-store');
        }
        $header = $psr->getHeaderLine('Authorization');
        if ($header !== '') {
            $decoded = preg_match('/^Basic ([A-Za-z0-9+\/=]+)$/iD', $header, $match) === 1 ? base64_decode($match[1], true) : false;
            if ($decoded === false || ! str_contains($decoded, ':') || isset($body['client_secret'])) {
                return $this->invalidClient();
            }
            [$id, $secret] = array_map('urldecode', explode(':', $decoded, 2));
            if ($id === '' || (isset($body['client_id']) && $body['client_id'] !== $id)) {
                return $this->invalidClient();
            }
            $body['client_id'] = $id;
            $body['client_secret'] = $secret;
            $psr = $psr->withoutHeader('Authorization');
        }
        if (! Str::isUuid($body['client_id'] ?? '')) {
            return $this->invalidClient();
        }
        $client = Passport::client()->newQuery()->whereKey($body['client_id'])->where('revoked', false)->first();
        if (! $client || ! DB::table('sso_clients')->where('client_id', $body['client_id'])->exists()
            || ($client->confidential() && ($body['client_secret'] ?? '') === '')) {
            return $this->invalidClient();
        }
        $psr = $psr->withParsedBody($body);
        $request->attributes->set('sso.authenticated_client', $body['client_id']);
        try {
            $response = DB::transaction(fn () => $server->make()->respondToAccessTokenRequest($psr, new PsrResponse));
        } catch (OAuthServerException $e) {
            // The failed exchange has rolled back. Persist revocation separately,
            // so replay cannot leave the originally issued token usable (RFC6749 4.1.2).
            if (is_string($codeId = $request->attributes->get('sso.replayed_code'))) {
                Passport::token()->newQuery()->whereIn('id', DB::table('sso_token_sessions')->where('code_id', $codeId)->select('token_id'))
                    ->update(['revoked' => true]);
            }
            $response = $e->generateHttpResponse(new PsrResponse);
        }

        return (new HttpFoundationFactory)->createResponse($response);
    }

    private function invalidClient(): Response
    {
        return response()->json(['error' => 'invalid_client'], 401)->header('Cache-Control', 'no-store')
            ->header('WWW-Authenticate', 'Basic realm="oidc"');
    }
}
