<?php

namespace LaravelSso\Tests\Conformance;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;
use LaravelSso\Server\SigningKeys;
use LaravelSso\Tests\Fixtures\CentralUser;
use LaravelSso\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** Project-owned normative checks, NOT the OpenID Foundation certification suite. */
#[Group('conformance')]
final class OidcCodeProfileTest extends TestCase
{
    private function fixture(bool $automatic = true, bool $confidential = false, string $redirect = 'https://one.test/cb'): array
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Conformance RP', [$redirect], $confidential);
        DB::table('sso_clients')->insert(['client_id' => $client->id, 'auto_approve' => $automatic]);
        $user = CentralUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => bcrypt('test-secret')]);
        $params = ['response_type' => 'code', 'client_id' => $client->id, 'redirect_uri' => $redirect,
            'scope' => 'openid profile email', 'state' => 's & " <>', 'nonce' => 'nonce-test',
            'code_challenge_method' => 'S256', 'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', str_repeat('v', 64), true))];

        return [$client, $user, $params];
    }

    private function responseQuery($response): array
    {
        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query;
    }

    private function redeem(array $params, string $code, array $extra = [])
    {
        return $this->withoutHeader('Authorization')->post('/oidc/token', array_merge([
            'grant_type' => 'authorization_code', 'client_id' => $params['client_id'], 'code' => $code,
            'redirect_uri' => $params['redirect_uri'], 'code_verifier' => str_repeat('v', 64),
        ], $extra));
    }

    public function test_core_3_1_2_1_nonce_and_state_are_optional_in_code_flow(): void
    {
        [, $user, $params] = $this->fixture();
        unset($params['nonce'], $params['state']);
        $query = $this->responseQuery($this->actingAs($user)->get('/oidc/authorize?'.http_build_query($params)));
        $this->assertArrayNotHasKey('state', $query);
        $this->assertSame('https://identity.test', $query['iss']);
        $token = $this->redeem($params, $query['code'])->assertOk();
        $claims = JWT::decode($token->json('id_token'), JWK::parseKeySet(app(SigningKeys::class)->jwks()));
        $this->assertFalse(property_exists($claims, 'nonce'));
        $this->assertSame(JWT::urlsafeB64Encode(substr(hash('sha256', $token->json('access_token'), true), 0, 16)), $claims->at_hash);
    }

    public static function invalidParameters(): array
    {
        return [
            'none cannot combine' => [['prompt' => 'none login'], 'invalid_request'],
            'unknown prompt' => [['prompt' => 'magic'], 'invalid_request'],
            'negative max age' => [['max_age' => '-1'], 'invalid_request'],
            'decimal max age' => [['max_age' => '1.1'], 'invalid_request'],
            'empty nonce' => [['nonce' => ''], 'invalid_request'],
            'PKCE plain' => [['code_challenge_method' => 'plain'], 'invalid_request'],
            'short challenge' => [['code_challenge' => 'short'], 'invalid_request'],
            'unknown scope' => [['scope' => 'openid admin'], 'invalid_scope'],
            'openid required' => [['scope' => 'email'], 'invalid_scope'],
            'implicit disabled' => [['response_type' => 'token'], 'unsupported_response_type'],
            'missing response type' => [['response_type' => ''], 'invalid_request'],
            'request object not supported' => [['request' => 'signed.jwt'], 'request_not_supported'],
            'request URI never fetched' => [['request_uri' => 'http://169.254.169.254/'], 'request_uri_not_supported'],
            'dynamic registration not supported' => [['registration' => '{}'], 'registration_not_supported'],
        ];
    }

    #[DataProvider('invalidParameters')]
    public function test_core_3_1_2_6_errors_echo_state_only_to_exact_registered_redirect(array $changes, string $error): void
    {
        [, , $params] = $this->fixture();
        $params = array_merge($params, $changes);
        $response = $this->get('/oidc/authorize?'.http_build_query($params));
        $query = $this->responseQuery($response);
        $this->assertSame($error, $query['error']);
        $this->assertSame($params['state'], $query['state']);
        $this->assertSame('https://one.test/cb', strtok($response->headers->get('Location'), '?'));
        $this->assertArrayNotHasKey('code', $query);
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
    }

    public function test_core_3_1_2_6_unsupported_response_mode_fails_locally_without_redirect(): void
    {
        [, , $params] = $this->fixture();
        $params['response_mode'] = 'fragment';
        $response = $this->get('/oidc/authorize?'.http_build_query($params))->assertStatus(400);
        $this->assertFalse($response->headers->has('Location'));
    }

    public function test_core_3_1_2_3_prompt_none_does_not_authenticate_or_show_ui(): void
    {
        [, $user, $params] = $this->fixture();
        $params['prompt'] = 'none';
        $this->assertSame('login_required', $this->responseQuery($this->get('/oidc/authorize?'.http_build_query($params)))['error']);
        $this->assertSame(0, DB::table('sso_sessions')->count());
        $query = $this->responseQuery($this->actingAs($user)->get('/oidc/authorize?'.http_build_query($params)));
        $this->assertArrayHasKey('code', $query);
    }

    public function test_core_3_1_2_6_prompt_none_without_consent_returns_consent_required(): void
    {
        [, $user, $params] = $this->fixture(false);
        $params['prompt'] = 'none';
        $this->assertSame('consent_required', $this->responseQuery($this->actingAs($user)->get('/oidc/authorize?'.http_build_query($params)))['error']);
    }

    public static function reauthentication(): array
    {
        return ['login' => [['prompt' => 'login']], 'zero max age' => [['max_age' => '0']],
            'expired max age' => [['max_age' => '60']]];
    }

    #[DataProvider('reauthentication')]
    public function test_core_3_1_2_1_reauthentication_requires_a_new_login_event_bound_to_request(array $changes): void
    {
        [, $user, $params] = $this->fixture();
        $params = array_merge($params, $changes);
        $this->actingAs($user)->withSession(['sso.auth_time' => time() - 1000])
            ->get('/oidc/authorize?'.http_build_query($params))->assertRedirect(route('login'));
        // setUser / a stale guard alone must not satisfy a challenge.
        $this->actingAs($user)->get('/oidc/authorize?'.http_build_query($params))->assertRedirect(route('login'));
        Auth::guard('web')->login($user);
        $query = $this->responseQuery($this->get('/oidc/authorize?'.http_build_query($params)));
        $token = $this->redeem($params, $query['code'])->assertOk();
        $claims = JWT::decode($token->json('id_token'), JWK::parseKeySet(app(SigningKeys::class)->jwks()));
        $this->assertGreaterThanOrEqual(time() - 5, $claims->auth_time);
        $this->assertNull(session('sso.reauthentication'));
    }

    public function test_core_3_1_2_1_login_consent_and_select_account_prompts_combine(): void
    {
        [, $user, $params] = $this->fixture();
        $params['prompt'] = 'login consent select_account';
        $this->actingAs($user)->get('/oidc/authorize?'.http_build_query($params))->assertRedirect(route('login'));
        Auth::guard('web')->login($user);
        $this->get('/oidc/authorize?'.http_build_query($params))->assertOk()->assertSee('Usar otra cuenta');
        $transaction = array_key_first(session('sso.consent'));
        $query = $this->responseQuery($this->post('/oidc/consent', ['transaction' => $transaction, 'approve' => '0']));
        $this->assertSame('access_denied', $query['error']);
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
    }

    public function test_core_3_1_2_1_unknown_hints_and_locales_do_not_grant_identity(): void
    {
        [, , $params] = $this->fixture();
        $params += ['prompt' => 'none', 'login_hint' => 'admin@example.test', 'ui_locales' => 'unknown-ZZ', 'extension' => 'ignored'];
        $this->assertSame('login_required', $this->responseQuery($this->get('/oidc/authorize?'.http_build_query($params)))['error']);
    }

    public function test_core_3_1_2_1_id_token_hint_does_not_override_authenticated_subject(): void
    {
        [$client, $user, $params] = $this->fixture();
        $params['prompt'] = 'none';
        $params['id_token_hint'] = app(SigningKeys::class)->sign(['iss' => 'https://identity.test', 'sub' => 'another-user',
            'aud' => $client->id, 'iat' => time(), 'exp' => time() + 60]);
        $this->assertSame('login_required', $this->responseQuery($this->actingAs($user)->get('/oidc/authorize?'.http_build_query($params)))['error']);
    }

    public function test_core_3_1_2_1_post_authorization_preserves_exact_redirect_query_and_state(): void
    {
        [, $user, $params] = $this->fixture(redirect: 'https://one.test/cb?tenant=one');
        $response = $this->actingAs($user)->post('/oidc/authorize', $params);
        $query = $this->responseQuery($response);
        $this->assertSame('one', $query['tenant']);
        $this->assertSame($params['state'], $query['state']);
        $this->redeem($params, $query['code'])->assertOk();
    }

    public function test_form_post_response_mode_encodes_values_without_interpolating_markup(): void
    {
        [, $user, $params] = $this->fixture();
        $params += ['response_mode' => 'form_post'];
        $params['state'] = '"><script>alert(1)</script>';
        $response = $this->actingAs($user)->get('/oidc/authorize?'.http_build_query($params))->assertOk();
        $response->assertSee('action="https://one.test/cb"', false)->assertSee('method="post"', false);
        $response->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        preg_match('/name="code" value="([^"]+)"/', $response->getContent(), $match);
        $this->redeem($params, html_entity_decode($match[1]))->assertOk();
    }

    public function test_core_9_1_client_secret_basic_decodes_form_encoded_credentials(): void
    {
        [$client, $user, $params] = $this->fixture(confidential: true);
        $secret = 's:e+cret with spaces';
        $client->forceFill(['secret' => $secret])->save();
        $query = $this->responseQuery($this->actingAs($user)->get('/oidc/authorize?'.http_build_query($params)));
        $header = 'Basic '.base64_encode(urlencode($client->id).':'.urlencode($secret));
        $response = $this->withHeader('Authorization', $header)->post('/oidc/token', [
            'grant_type' => 'authorization_code', 'code' => $query['code'], 'code_verifier' => str_repeat('v', 64),
            'redirect_uri' => $params['redirect_uri'],
        ])->assertOk();
        $this->assertIsString($response->json('id_token'));
    }

    public function test_rfc6749_2_3_multiple_auth_methods_and_missing_secrets_are_rejected(): void
    {
        [$client, $user, $params] = $this->fixture(confidential: true);
        $query = $this->responseQuery($this->actingAs($user)->get('/oidc/authorize?'.http_build_query($params)));
        $body = ['grant_type' => 'authorization_code', 'client_id' => $client->id, 'code' => $query['code'],
            'redirect_uri' => $params['redirect_uri'], 'code_verifier' => str_repeat('v', 64)];
        $this->post('/oidc/token', $body)->assertUnauthorized()->assertJsonPath('error', 'invalid_client');
        $this->withHeader('Authorization', 'Basic '.base64_encode($client->id.':'.$client->plainSecret))
            ->post('/oidc/token', $body + ['client_secret' => $client->plainSecret])->assertUnauthorized();
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
    }

    public function test_core_5_3_userinfo_supports_get_and_post_and_respects_scopes(): void
    {
        [, $user, $params] = $this->fixture();
        $params['scope'] = 'openid';
        $query = $this->responseQuery($this->actingAs($user)->get('/oidc/authorize?'.http_build_query($params)));
        $token = $this->redeem($params, $query['code'])->assertOk();
        foreach (['GET', 'POST'] as $method) {
            $this->withToken($token->json('access_token'));
            $response = $method === 'GET' ? $this->getJson('/oidc/userinfo') : $this->postJson('/oidc/userinfo');
            $response->assertOk()
                ->assertExactJson(['sub' => (string) $user->id]);
        }
        $this->withoutHeader('Authorization')->get('/oidc/userinfo')->assertUnauthorized()->assertHeader('WWW-Authenticate');
        $this->post('/oidc/userinfo', ['access_token' => $token->json('access_token')])->assertOk()
            ->assertExactJson(['sub' => (string) $user->id]);
        $this->withToken($token->json('access_token'))->post('/oidc/userinfo', ['access_token' => $token->json('access_token')])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_request');
        $this->withoutHeader('Authorization')->get('/oidc/userinfo?access_token=do-not-log-tokens')
            ->assertStatus(400)->assertJsonPath('error', 'invalid_request');
    }

    public function test_rfc6749_duplicate_parameters_fail_without_redirecting_or_issuing_tokens(): void
    {
        [, , $params] = $this->fixture();
        $response = $this->get('/oidc/authorize?'.http_build_query($params).'&client_id='.$params['client_id'])->assertStatus(400);
        $this->assertFalse($response->headers->has('Location'));
        $this->call('POST', '/oidc/token', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            'grant_type=authorization_code&grant_type=authorization_code')->assertStatus(400);
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
    }

    public function test_discovery_3_metadata_matches_executed_capabilities(): void
    {
        $this->get('/.well-known/openid-configuration')->assertOk()
            ->assertJsonPath('response_types_supported', ['code'])
            ->assertJsonPath('response_modes_supported', ['query', 'form_post'])
            ->assertJsonPath('request_parameter_supported', false)->assertJsonPath('claims_parameter_supported', false)
            ->assertJsonPath('authorization_response_iss_parameter_supported', true)
            ->assertJsonPath('token_endpoint_auth_methods_supported', ['client_secret_basic', 'client_secret_post', 'none']);
    }

    public function test_core_3_1_3_2_code_is_bound_to_original_client_and_exact_redirect(): void
    {
        [, $user, $params] = $this->fixture();
        $other = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Other RP', [$params['redirect_uri']], false);
        DB::table('sso_clients')->insert(['client_id' => $other->id, 'auto_approve' => true]);
        $query = $this->responseQuery($this->actingAs($user)->get('/oidc/authorize?'.http_build_query($params)));
        $this->redeem($params, $query['code'], ['client_id' => $other->id])->assertStatus(400);
        $this->redeem($params, $query['code'], ['redirect_uri' => 'https://evil.test/cb'])->assertStatus(400);
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
        $this->redeem($params, $query['code'])->assertOk();
    }
}
