<?php

namespace LaravelSso\Tests\Conformance;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;
use LaravelSso\Server\SigningKeys;
use LaravelSso\Server\UserClaims;
use LaravelSso\Tests\Fixtures\CentralUser;
use LaravelSso\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('conformance')]
final class ClaimsTest extends TestCase
{
    private function fixture(array $claims = [], string $scope = 'openid', bool $automatic = true): array
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Claims RP', ['https://one.test/cb'], false);
        DB::table('sso_clients')->insert(['client_id' => $client->id, 'auto_approve' => $automatic]);
        $user = CentralUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => bcrypt('test-secret')]);
        $params = ['response_type' => 'code', 'client_id' => $client->id, 'redirect_uri' => 'https://one.test/cb',
            'scope' => $scope, 'nonce' => 'nonce', 'state' => 'state', 'code_challenge_method' => 'S256',
            'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', str_repeat('v', 64), true))];
        if ($claims !== []) {
            $params['claims'] = json_encode($claims, JSON_THROW_ON_ERROR);
        }
        $this->actingAs($user);

        return [$user, $params];
    }

    private function responseQuery($response): array
    {
        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query;
    }

    private function redeem(array $params, string $code, array $extra = [])
    {
        return $this->withoutHeader('Authorization')->post('/oidc/token', $extra + ['grant_type' => 'authorization_code',
            'client_id' => $params['client_id'], 'redirect_uri' => $params['redirect_uri'],
            'code' => $code, 'code_verifier' => str_repeat('v', 64)])->assertOk();
    }

    private function issue(array $params, array $extra = [])
    {
        $query = $this->responseQuery($this->get('/oidc/authorize?'.http_build_query($params)));

        return $this->redeem($params, $query['code'], $extra);
    }

    private function decode($token): object
    {
        return JWT::decode($token->json('id_token'), JWK::parseKeySet(app(SigningKeys::class)->jwks()));
    }

    public function test_essential_name_is_bound_to_userinfo_without_granting_profile_or_email(): void
    {
        [, $params] = $this->fixture(['userinfo' => ['name' => ['essential' => true]]]);
        $token = $this->issue($params, ['claims' => '{"userinfo":{"email":null}}', 'scope' => 'openid profile email']);
        $this->assertFalse(property_exists($this->decode($token), 'name'));
        $this->withToken($token->json('access_token'))->getJson('/oidc/userinfo')->assertExactJson(['sub' => '1', 'name' => 'Ada']);
    }

    public function test_explicit_id_token_claim_does_not_expand_userinfo(): void
    {
        [, $params] = $this->fixture(['id_token' => ['name' => null]]);
        $token = $this->issue($params);
        $this->assertSame('Ada', $this->decode($token)->name);
        $this->withToken($token->json('access_token'))->getJson('/oidc/userinfo')->assertExactJson(['sub' => '1']);
    }

    public function test_unknown_claim_types_are_ignored_without_parsing_or_exposing_private_values(): void
    {
        [, $params] = $this->fixture(['userinfo' => ['address' => ['essential' => true, 'value' => ['formatted' => 'Unknown']],
            'password' => ['value' => ['untrusted' => true]], 'name' => null]]);
        $token = $this->issue($params);
        $this->withToken($token->json('access_token'))->getJson('/oidc/userinfo')->assertExactJson(['sub' => '1', 'name' => 'Ada']);
    }

    public static function verificationValues(): array
    {
        return [[false, false], [0, false], ['0', false], [[], false], ['not-a-date', false],
            [true, true], ['2026-11-13 12:00:00', true]];
    }

    #[DataProvider('verificationValues')]
    public function test_email_verification_never_promotes_false_or_invalid_attribute_values(mixed $value, bool $expected): void
    {
        $user = new CentralUser;
        $user->setRawAttributes(['email' => 'ada@example.test', 'email_verified_at' => $value]);
        $this->assertSame($expected, app(UserClaims::class)->available($user)['email_verified']);
    }

    public function test_claim_value_filters_apply_even_when_profile_scope_requests_the_claim(): void
    {
        [, $params] = $this->fixture(['userinfo' => ['name' => ['value' => 'Someone else']]], 'openid profile');
        $token = $this->issue($params);
        $this->withToken($token->json('access_token'))->getJson('/oidc/userinfo')->assertOk()->assertJsonMissingPath('name');
    }

    public function test_unavailable_essential_and_private_attributes_are_not_invented_or_disclosed(): void
    {
        [, $params] = $this->fixture(['userinfo' => ['given_name' => ['essential' => true], 'password' => null, 'is_active' => null]]);
        $token = $this->issue($params);
        $this->withToken($token->json('access_token'))->getJson('/oidc/userinfo')->assertExactJson(['sub' => '1']);
    }

    public function test_consent_shows_requested_attributes_and_cannot_replace_the_original_grant(): void
    {
        [, $params] = $this->fixture(['userinfo' => ['name' => null]], automatic: false);
        $this->get('/oidc/authorize?'.http_build_query($params))->assertOk()->assertSee('Atributos adicionales solicitados: name');
        $transaction = array_key_first(session('sso.consent'));
        $query = $this->responseQuery($this->post('/oidc/consent', ['transaction' => $transaction, 'approve' => '1', 'claims' => '{"userinfo":{"email":null}}']));
        $token = $this->redeem($params, $query['code']);
        $this->withToken($token->json('access_token'))->getJson('/oidc/userinfo')->assertExactJson(['sub' => '1', 'name' => 'Ada']);
    }

    public static function subjects(): array
    {
        return ['ID Token' => ['id_token'], 'UserInfo' => ['userinfo']];
    }

    #[DataProvider('subjects')]
    public function test_subject_constraint_never_selects_another_identity(string $target): void
    {
        [, $params] = $this->fixture([$target => ['sub' => ['value' => 'another']]]);
        $query = $this->responseQuery($this->get('/oidc/authorize?'.http_build_query($params)));
        $this->assertSame('access_denied', $query['error']);
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
    }

    public function test_subject_values_selects_the_authenticated_user_and_unavailable_voluntary_acr_returns_actual_class(): void
    {
        [, $params] = $this->fixture(['id_token' => ['sub' => ['values' => ['another', '1']]]]);
        $params['acr_values'] = '2 urn:unavailable:mfa';
        $token = $this->issue($params);
        $this->assertSame('1', $this->decode($token)->sub);
        $this->assertSame('0', $this->decode($token)->acr);
    }

    public function test_essential_unavailable_acr_fails_authentication_instead_of_echoing_requested_assurance(): void
    {
        [, $params] = $this->fixture(['id_token' => ['acr' => ['essential' => true, 'values' => ['2']]]]);
        $query = $this->responseQuery($this->get('/oidc/authorize?'.http_build_query($params)));
        $this->assertSame('access_denied', $query['error']);
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
    }

    public function test_optional_auth_time_constraint_can_omit_the_claim_but_cannot_contradict_max_age(): void
    {
        [, $params] = $this->fixture(['id_token' => ['auth_time' => ['value' => 0]]]);
        $this->assertFalse(property_exists($this->decode($this->issue($params)), 'auth_time'));
        $params['max_age'] = '10000';
        $this->withSession(['sso.auth_time' => time()]);
        $query = $this->responseQuery($this->get('/oidc/authorize?'.http_build_query($params)));
        $this->assertSame('access_denied', $query['error']);
    }

    public function test_profile_mapper_uses_only_real_typed_attributes_and_never_loads_relationships(): void
    {
        $user = new CentralUser;
        $user->setRawAttributes(['name' => 'Ada', 'given_name' => 'Ada', 'family_name' => null,
            'picture' => ['invalid'], 'updated_at' => '2026-11-13 12:00:00', 'email' => 'ada@example.test',
            'email_verified_at' => false, 'password' => 'never-expose']);
        $claims = app(UserClaims::class)->available($user);
        $this->assertSame('Ada', $claims['given_name']);
        $this->assertIsInt($claims['updated_at']);
        $this->assertFalse($claims['email_verified']);
        foreach (['family_name', 'picture', 'password', 'birthdate'] as $name) {
            $this->assertArrayNotHasKey($name, $claims);
        }
    }

    public static function malformedClaims(): array
    {
        return ['invalid JSON' => ['{broken'], 'list' => ['[]'], 'userinfo list' => ['{"userinfo":[]}'],
            'claim array' => ['{"userinfo":{"name":[]}}'], 'essential type' => ['{"userinfo":{"name":{"essential":"true"}}}'],
            'values scalar' => ['{"userinfo":{"name":{"values":"Ada"}}}'], 'empty values' => ['{"userinfo":{"name":{"values":[]}}}'],
            'subject type' => ['{"id_token":{"sub":{"value":1}}}'], 'both qualifiers' => ['{"userinfo":{"name":{"value":"Ada","values":["Ada"]}}}'],
            'oversized' => [str_repeat(' ', 4097)]];
    }

    #[DataProvider('malformedClaims')]
    public function test_malformed_claim_requests_fail_before_issuing_codes(string $json): void
    {
        [, $params] = $this->fixture();
        $params['claims'] = $json;
        $query = $this->responseQuery($this->get('/oidc/authorize?'.http_build_query($params)));
        $this->assertSame('invalid_request', $query['error']);
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
    }
}
