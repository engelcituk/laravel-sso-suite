<?php

namespace LaravelSso\Tests\Conformance;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\ClientRepository;
use LaravelSso\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('conformance')]
final class OptionalCapabilitiesTest extends TestCase
{
    private function requestParameters(): array
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Capability probe', ['https://one.test/cb'], false);
        DB::table('sso_clients')->insert(['client_id' => $client->id, 'auto_approve' => true]);

        return ['response_type' => 'code', 'client_id' => $client->id, 'redirect_uri' => 'https://one.test/cb',
            'scope' => 'openid', 'code_challenge_method' => 'S256',
            'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', str_repeat('v', 64), true))];
    }

    public static function optionalScopes(): array
    {
        return [['address'], ['phone'], ['offline_access']];
    }

    #[DataProvider('optionalScopes')]
    public function test_unadvertised_optional_scopes_are_rejected_without_issuing_grants(string $scope): void
    {
        $metadata = $this->getJson('/.well-known/openid-configuration')->assertOk()->json();
        $this->assertNotContains($scope, $metadata['scopes_supported']);
        $params = $this->requestParameters();
        $params['scope'] .= ' '.$scope;
        $response = $this->get('/oidc/authorize?'.http_build_query($params))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('invalid_scope', $query['error']);
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
    }

    public function test_refresh_grant_is_explicitly_unsupported_by_oidc_endpoint(): void
    {
        $metadata = $this->getJson('/.well-known/openid-configuration')->assertOk()->json();
        $this->assertNotContains('refresh_token', $metadata['grant_types_supported']);
        $this->post('/oidc/token', ['grant_type' => 'refresh_token', 'refresh_token' => 'untrusted'])
            ->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
    }

    public function test_request_uri_is_never_fetched_and_request_object_never_grants_identity(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $metadata = $this->getJson('/.well-known/openid-configuration')->assertOk()->json();
        $this->assertFalse($metadata['request_parameter_supported']);
        $this->assertFalse($metadata['request_uri_parameter_supported']);
        $params = $this->requestParameters();
        foreach (['request_uri' => 'https://127.0.0.1/private', 'request' => 'untrusted.jwt.payload'] as $key => $value) {
            $response = $this->get('/oidc/authorize?'.http_build_query($params + [$key => $value]))->assertRedirect();
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
            $this->assertSame($key === 'request' ? 'request_not_supported' : 'request_uri_not_supported', $query['error']);
        }
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
    }
}
