<?php

namespace LaravelSso\Tests;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ClientRepository;
use LaravelSso\Server\GlobalSessions;
use LaravelSso\Server\SigningKeys;
use LaravelSso\Tests\Fixtures\CentralUser;

final class ProtocolTest extends TestCase
{
    private function authorization(array $overrides = []): array
    {
        $user = CentralUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => bcrypt('secret')]);
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('One', ['https://one.test/sso/callback'], false);
        DB::table('sso_clients')->insert(['client_id' => $client->id, 'auto_approve' => true]);
        $verifier = str_repeat('a', 64);
        $query = array_merge([
            'client_id' => $client->id, 'response_type' => 'code', 'redirect_uri' => 'https://one.test/sso/callback',
            'scope' => 'openid profile email', 'state' => 'state-test', 'nonce' => 'nonce-test',
            'code_challenge_method' => 'S256',
            'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', $verifier, true)),
        ], $overrides);
        $response = $this->actingAs($user, 'web')->get('/oidc/authorize?'.http_build_query($query));

        return [$response, $client, $verifier, $user];
    }

    private function exchange($client, string $code, string $verifier): TestResponse
    {
        return $this->withoutHeader('Authorization')->postJson('/oidc/token', ['grant_type' => 'authorization_code', 'client_id' => $client->id,
            'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => 'https://one.test/sso/callback']);
    }

    public function test_real_passport_code_exchange_id_token_userinfo_and_replay(): void
    {
        [$response, $client, $verifier, $user] = $this->authorization();
        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $token = $this->exchange($client, $query['code'], $verifier)->assertOk();
        $token->assertJsonMissingPath('refresh_token');
        $claims = JWT::decode($token->json('id_token'), JWK::parseKeySet(app(SigningKeys::class)->jwks()));
        $this->assertSame('nonce-test', $claims->nonce);
        $this->assertSame((string) $user->id, $claims->sub);
        $this->assertSame($client->id, $claims->aud);
        $this->assertNotEmpty($claims->sid);
        $this->withToken($token->json('access_token'))->getJson('/oidc/userinfo')->assertOk()
            ->assertJsonPath('sub', (string) $user->id)->assertJsonPath('name', 'Ada');
        $this->exchange($client, $query['code'], $verifier)->assertStatus(400);
        $this->assertSame(1, DB::table('oauth_access_tokens')->count());
    }

    public function test_wrong_pkce_does_not_consume_code(): void
    {
        [$response, $client, $verifier] = $this->authorization();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->exchange($client, $query['code'], str_repeat('b', 64))->assertStatus(400);
        $this->exchange($client, $query['code'], $verifier)->assertOk();
    }

    public function test_code_replay_revokes_only_tokens_issued_for_that_code_and_client(): void
    {
        [$response, $client, $verifier] = $this->authorization();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $first = $this->exchange($client, $query['code'], $verifier)->assertOk()->json('access_token');
        $params = ['client_id' => $client->id, 'response_type' => 'code', 'redirect_uri' => 'https://one.test/sso/callback', 'scope' => 'openid',
            'code_challenge_method' => 'S256', 'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', $verifier, true))];
        $secondResponse = $this->get('/oidc/authorize?'.http_build_query($params))->assertRedirect();
        parse_str(parse_url($secondResponse->headers->get('Location'), PHP_URL_QUERY), $secondQuery);
        $second = $this->exchange($client, $secondQuery['code'], $verifier)->assertOk()->json('access_token');
        $other = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Other', ['https://one.test/sso/callback'], false);
        DB::table('sso_clients')->insert(['client_id' => $other->id, 'auto_approve' => true]);
        $this->exchange($other, $secondQuery['code'], $verifier)->assertStatus(400);
        $this->withToken($second)->getJson('/oidc/userinfo')->assertOk();
        $this->exchange($client, $query['code'], $verifier)->assertStatus(400);
        $this->withToken($first)->getJson('/oidc/userinfo')->assertStatus(401);
        $this->withToken($second)->getJson('/oidc/userinfo')->assertOk();
    }

    public function test_unregistered_callback_is_rejected_without_redirect(): void
    {
        [$response] = $this->authorization(['redirect_uri' => 'https://evil.test/callback']);
        $response->assertStatus(400);
        $this->assertFalse($response->headers->has('Location'));
    }

    public function test_plain_pkce_is_rejected(): void
    {
        [$response] = $this->authorization(['code_challenge_method' => 'plain']);
        $response->assertRedirect();
        $this->assertStringContainsString('error=invalid_request', $response->headers->get('Location'));
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
    }

    public function test_discovery_publishes_only_implemented_grants(): void
    {
        $this->getJson('/.well-known/openid-configuration')->assertOk()
            ->assertJsonPath('issuer', 'https://identity.test')->assertJsonPath('grant_types_supported', ['authorization_code'])
            ->assertJsonPath('end_session_endpoint', 'https://identity.test/oidc/logout');
        $this->getJson('/oidc/jwks')->assertOk()->assertJsonPath('keys.0.alg', 'RS256');
        $this->postJson('/oidc/token', ['grant_type' => 'password'])->assertStatus(400);
    }

    public function test_revocation_and_suspension_reject_existing_access_tokens(): void
    {
        [$response, $client, $verifier, $user] = $this->authorization();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $token = $this->exchange($client, $query['code'], $verifier)->assertOk();
        $sid = DB::table('sso_token_sessions')->value('sid');
        $user->forceFill(['is_active' => false])->save();
        $this->withToken($token->json('access_token'))->getJson('/oidc/session')->assertOk()->assertJsonPath('active', false);
        $user->forceFill(['is_active' => true])->save();
        app(GlobalSessions::class)->revoke($sid);
        $this->withToken($token->json('access_token'))->getJson('/oidc/session')->assertStatus(401);
    }

    public function test_revoked_session_cannot_exchange_previously_issued_code(): void
    {
        [$response, $client, $verifier] = $this->authorization();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        app(GlobalSessions::class)->revoke(DB::table('sso_auth_contexts')->value('sid'));
        $this->exchange($client, $query['code'], $verifier)->assertStatus(400);
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
    }

    public function test_consent_binds_nonce_to_original_transaction_and_is_single_use(): void
    {
        [$response, $client, $verifier] = $this->authorization(['prompt' => 'consent']);
        $response->assertOk();
        $pending = session('sso.consent');
        $transaction = array_key_first($pending);
        $approval = $this->post('/oidc/consent', ['transaction' => $transaction, 'approve' => '1'])->assertRedirect();
        parse_str(parse_url($approval->headers->get('Location'), PHP_URL_QUERY), $query);
        $token = $this->exchange($client, $query['code'], $verifier)->assertOk();
        $claims = JWT::decode($token->json('id_token'), JWK::parseKeySet(app(SigningKeys::class)->jwks()));
        $this->assertSame('nonce-test', $claims->nonce);
        $this->post('/oidc/consent', ['transaction' => $transaction, 'approve' => '1'])->assertStatus(400);
    }

    public function test_unknown_client_is_rejected_without_redirect(): void
    {
        $response = $this->get('/oidc/authorize?'.http_build_query(['client_id' => '00000000-0000-4000-8000-000000000000',
            'response_type' => 'code', 'redirect_uri' => 'https://evil.test/callback', 'scope' => 'openid']));
        $response->assertStatus(400);
        $this->assertFalse($response->headers->has('Location'));
    }
}
