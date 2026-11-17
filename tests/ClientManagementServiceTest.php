<?php

namespace LaravelSso\Tests;

use Firebase\JWT\JWT;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\ClientRepository;
use LaravelSso\Contracts\AuditEvent;
use LaravelSso\Server\ClientChanges;
use LaravelSso\Server\ClientDefinition;
use LaravelSso\Server\Clients;
use LaravelSso\Server\Contracts\ManagesClients;
use LaravelSso\Server\Contracts\RegistersClients;
use LaravelSso\Tests\Fixtures\CentralUser;
use PHPUnit\Framework\Attributes\DataProvider;

final class ClientManagementServiceTest extends TestCase
{
    private function create(bool $public = true, bool $firstParty = true): string
    {
        return app(RegistersClients::class)->create(new ClientDefinition('Example client', 'https://one.test/callback',
            public: $public, firstParty: $firstParty, restrictUsers: false))->id;
    }

    private function code(string $id, ?CentralUser $user = null): array
    {
        $user ??= CentralUser::first() ?? CentralUser::create(['name' => 'Keep', 'email' => 'keep@example.test', 'password' => bcrypt('keep-password')]);
        $verifier = str_repeat('v', 64);
        $uri = app(ManagesClients::class)->find($id)->redirectUris[0];
        $response = $this->actingAs($user, 'web')->get('/oidc/authorize?'.http_build_query([
            'client_id' => $id, 'response_type' => 'code', 'redirect_uri' => $uri, 'scope' => 'openid',
            'code_challenge_method' => 'S256', 'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', $verifier, true)),
        ]))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->assertArrayHasKey('code', $parameters);

        return ['grant_type' => 'authorization_code', 'client_id' => $id, 'code' => $parameters['code'],
            'code_verifier' => $verifier, 'redirect_uri' => $uri];
    }

    public function test_safe_paginated_listing_only_includes_suite_clients_and_never_secrets(): void
    {
        $registry = app(ManagesClients::class);
        $this->assertInstanceOf(Clients::class, $registry);
        $created = app(RegistersClients::class)->create(new ClientDefinition('Private', 'https://one.test/callback'));
        $second = $this->create();
        app(ClientRepository::class)->createAuthorizationCodeGrantClient('Other host OAuth', ['https://other.test/cb']);
        $registry->revoke($second);
        $this->assertSame(1, $registry->listing()->total());
        $this->assertSame($created->id, $registry->listing()->items()[0]->id);
        $this->assertSame($second, $registry->listing('revoked')->items()[0]->id);
        $page = $registry->listing('all', 1, 2);
        $this->assertSame(2, $page->total());
        $this->assertCount(1, $page->items());
        $this->assertSame(2, $page->currentPage());
        parse_str(parse_url($registry->listing('all', 1, 1)->nextPageUrl(), PHP_URL_QUERY), $next);
        $this->assertSame(['status' => 'all', 'per_page' => '1', 'page' => '2'], $next);
        $json = $registry->listing('all')->toJson();
        $this->assertStringNotContainsString('secret', $json);
        $this->assertStringNotContainsString($created->plainSecret(), $json);
        $this->assertStringNotContainsString(DB::table('oauth_clients')->where('id', $created->id)->value('secret'), $json);
        $this->assertSame(0, DB::table('central_users')->count());
    }

    #[DataProvider('invalidPages')]
    public function test_listing_rejects_invalid_bounds(string $status, int $size, int $page): void
    {
        $this->expectException(ValidationException::class);
        app(ManagesClients::class)->listing($status, $size, $page);
    }

    public static function invalidPages(): array
    {
        return [['unknown', 20, 1], ['active', 0, 1], ['active', 101, 1], ['active', 20, 0]];
    }

    public function test_partial_update_preserves_secret_type_assignments_and_users_and_can_clear_logout(): void
    {
        $id = $this->create(false);
        $before = DB::table('oauth_clients')->where('id', $id)->first();
        $user = CentralUser::create(['name' => 'Keep', 'email' => 'keep@example.test', 'password' => bcrypt('keep-password')]);
        $attributes = $user->fresh()->getAttributes();
        DB::table('sso_client_users')->insert(['client_id' => $id, 'subject' => (string) $user->id]);
        config(['sso-server.backchannel_allowed_urls' => ['https://one.test/backchannel']]);
        $registry = app(ManagesClients::class);
        $updated = $registry->update($id, new ClientChanges(name: 'Updated client', redirectUri: 'https://one.test/new',
            firstParty: false, requirePkce: false, restrictUsers: true,
            backchannelLogoutUri: 'https://one.test/backchannel', postLogoutRedirectUri: 'https://one.test/done'));
        $this->assertFalse($updated->public);
        $this->assertFalse($updated->requirePkce);
        $this->assertTrue($updated->restrictUsers);
        $this->assertFalse($updated->firstParty);
        $this->assertSame(['https://one.test/new'], $updated->redirectUris);
        $this->assertSame($before->secret, DB::table('oauth_clients')->where('id', $id)->value('secret'));
        $this->assertSame($before->grant_types, DB::table('oauth_clients')->where('id', $id)->value('grant_types'));
        $this->assertSame(1, DB::table('sso_client_users')->where('client_id', $id)->count());
        $cleared = $registry->update($id, new ClientChanges(backchannelLogoutUri: '', postLogoutRedirectUri: ''));
        $this->assertNull($cleared->backchannelLogoutUri);
        $this->assertSame([], $cleared->postLogoutRedirectUris);
        $this->assertSame('Updated client', $cleared->name);
        $this->assertSame($attributes, $user->fresh()->getAttributes());
    }

    #[DataProvider('invalidChanges')]
    public function test_invalid_update_is_atomic_and_uses_registration_rules(ClientChanges $changes): void
    {
        $id = $this->create();
        $before = app(ManagesClients::class)->find($id);
        Event::fake([AuditEvent::class]);
        try {
            app(ManagesClients::class)->update($id, $changes);
            $this->fail('Invalid update accepted.');
        } catch (ValidationException) {
            $this->assertEquals($before, app(ManagesClients::class)->find($id));
        }
        Event::assertNotDispatched(AuditEvent::class);
    }

    public static function invalidChanges(): array
    {
        return [[new ClientChanges(name: '')], [new ClientChanges(redirectUri: 'https://one.test/cb#bad')],
            [new ClientChanges(requirePkce: false)], [new ClientChanges(backchannelLogoutUri: 'https://untrusted.test/logout')],
            [new ClientChanges(postLogoutRedirectUri: 'javascript:alert(1)')]];
    }

    public function test_update_invalidates_existing_access_refresh_and_pending_codes_but_new_flow_works(): void
    {
        $id = $this->create();
        $token = $this->postJson('/oidc/token', $this->code($id))->assertOk()->json('access_token');
        $pending = $this->code($id);
        $tokenId = DB::table('oauth_access_tokens')->value('id');
        DB::table('oauth_refresh_tokens')->insert(['id' => str_repeat('r', 80), 'access_token_id' => $tokenId,
            'revoked' => false, 'expires_at' => now()->addHour()]);
        app(ManagesClients::class)->update($id, new ClientChanges(name: 'New name'));
        $this->assertTrue((bool) DB::table('oauth_refresh_tokens')->value('revoked'));
        $this->assertSame(0, DB::table('oauth_auth_codes')->where('revoked', false)->count());
        $this->withToken($token)->getJson('/oidc/userinfo')->assertUnauthorized();
        $this->withToken($token)->getJson('/oidc/session')->assertUnauthorized();
        $this->withHeader('Authorization', '');
        $this->postJson('/oidc/token', $pending)->assertStatus(400);
        $this->postJson('/oidc/token', $this->code($id))->assertOk();
    }

    public function test_noop_edit_keeps_tokens_valid_and_emits_no_event(): void
    {
        $id = $this->create();
        $token = $this->postJson('/oidc/token', $this->code($id))->assertOk()->json('access_token');
        Event::fake([AuditEvent::class]);
        app(ManagesClients::class)->update($id, new ClientChanges(name: 'Example client'));
        $this->withToken($token)->getJson('/oidc/userinfo')->assertOk();
        Event::assertNotDispatched(AuditEvent::class);
    }

    public function test_revocation_is_idempotent_application_scoped_and_blocks_real_oidc(): void
    {
        $target = $this->create();
        $other = $this->create();
        $user = CentralUser::create(['name' => 'Keep', 'email' => 'keep@example.test', 'password' => bcrypt('keep-password')]);
        $attributes = $user->fresh()->getAttributes();
        $targetToken = $this->postJson('/oidc/token', $this->code($target, $user))->assertOk()->json('access_token');
        $otherToken = $this->postJson('/oidc/token', $this->code($other, $user))->assertOk()->json('access_token');
        $pending = $this->code($target, $user);
        DB::table('sso_client_users')->insert(['client_id' => $target, 'subject' => (string) $user->id]);
        $sessions = DB::table('sso_sessions')->orderBy('sid')->get(['sid', 'subject', 'device_id', 'revoked', 'auth_time', 'expires_at'])->toArray();
        Event::fake([AuditEvent::class]);
        $this->assertTrue(app(ManagesClients::class)->revoke($target)->revoked);
        $this->assertTrue(app(ManagesClients::class)->revoke($target)->revoked);
        Event::assertDispatchedTimes(AuditEvent::class, 1);
        Event::assertDispatched(AuditEvent::class, fn ($event) => $event->name === 'client.revoked');
        $this->withToken($targetToken)->getJson('/oidc/userinfo')->assertUnauthorized();
        $this->withToken($targetToken)->getJson('/oidc/session')->assertUnauthorized();
        $this->withToken($otherToken)->getJson('/oidc/userinfo')->assertOk();
        $this->withHeader('Authorization', '');
        $this->postJson('/oidc/token', $pending)->assertUnauthorized()->assertJson(['error' => 'invalid_client']);
        $this->getJson('/oidc/authorize?'.http_build_query(['client_id' => $target, 'redirect_uri' => 'https://one.test/callback']))->assertStatus(400);
        $this->assertSame($attributes, $user->fresh()->getAttributes());
        $this->assertEquals($sessions, DB::table('sso_sessions')->orderBy('sid')->get(['sid', 'subject', 'device_id', 'revoked', 'auth_time', 'expires_at'])->toArray());
        $this->assertSame(1, DB::table('sso_client_users')->where('client_id', $target)->count());
        $this->assertSame(0, DB::table('sso_logout_deliveries')->count());
        $this->expectException(\RuntimeException::class);
        app(ManagesClients::class)->update($target, new ClientChanges(name: 'Reactivate'));
    }

    public function test_pending_consent_cannot_send_code_to_a_removed_redirect(): void
    {
        $id = $this->create(firstParty: false);
        $user = CentralUser::create(['name' => 'Keep', 'email' => 'keep@example.test', 'password' => bcrypt('keep-password')]);
        $response = $this->actingAs($user, 'web')->get('/oidc/authorize?'.http_build_query(['client_id' => $id,
            'response_type' => 'code', 'redirect_uri' => 'https://one.test/callback', 'scope' => 'openid',
            'code_challenge_method' => 'S256', 'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', str_repeat('v', 64), true)),
        ]))->assertOk();
        $transaction = $response->viewData('transaction');
        app(ManagesClients::class)->update($id, new ClientChanges(redirectUri: 'https://one.test/new'));
        $this->post('/oidc/consent', ['transaction' => $transaction, 'approve' => '1'])->assertStatus(400)->assertHeaderMissing('Location');
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
    }

    public function test_host_transaction_rolls_back_edit_revocation_tokens_and_audit(): void
    {
        $id = $this->create();
        $token = $this->postJson('/oidc/token', $this->code($id))->assertOk()->json('access_token');
        Event::fake([AuditEvent::class]);
        try {
            DB::transaction(function () use ($id): void {
                app(ManagesClients::class)->update($id, new ClientChanges(name: 'Rollback'));
                app(ManagesClients::class)->revoke($id);
                Event::assertNotDispatched(AuditEvent::class);
                throw new \RuntimeException('Host rollback');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Host rollback', $error->getMessage());
        }
        $this->assertSame('Example client', app(ManagesClients::class)->find($id)->name);
        $this->assertFalse(app(ManagesClients::class)->find($id)->revoked);
        $this->withToken($token)->getJson('/oidc/userinfo')->assertOk();
        Event::assertNotDispatched(AuditEvent::class);
    }

    public function test_partial_edit_preserves_legacy_url_lists_and_audit_excludes_admin_data(): void
    {
        $id = $this->create(false);
        $redirects = ['https://one.test/callback', 'https://one.test/second'];
        $postLogout = ['https://one.test/done', 'https://one.test/second-done'];
        DB::table('oauth_clients')->where('id', $id)->update(['redirect_uris' => json_encode($redirects)]);
        DB::table('sso_clients')->where('client_id', $id)->update(['post_logout_redirect_uris' => json_encode($postLogout)]);
        Event::fake([AuditEvent::class]);
        $updated = app(ManagesClients::class)->update($id, new ClientChanges(name: 'Private new name'));
        $this->assertSame($redirects, $updated->redirectUris);
        $this->assertSame($postLogout, $updated->postLogoutRedirectUris);
        Event::assertDispatchedTimes(AuditEvent::class, 1);
        Event::assertDispatched(AuditEvent::class, fn ($event) => $event->name === 'client.updated'
            && ! str_contains(json_encode($event), $id) && ! str_contains(json_encode($event), 'Private new name')
            && ! str_contains(json_encode($event), 'one.test'));
    }

    public function test_sql_failure_does_not_leave_partial_edits(): void
    {
        $id = $this->create();
        Schema::drop('oauth_auth_codes');
        try {
            app(ManagesClients::class)->update($id, new ClientChanges(name: 'Must rollback'));
            $this->fail('Missing table accepted.');
        } catch (QueryException) {
            $this->assertSame('Example client', app(ManagesClients::class)->find($id)->name);
        }
    }

    public function test_unknown_invalid_and_unmanaged_ids_cannot_be_mutated(): void
    {
        $foreign = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Host OAuth', ['https://other.test/cb']);
        foreach (['not-a-uuid', '00000000-0000-4000-8000-000000000042', $foreign->id] as $id) {
            foreach (['find', 'update', 'revoke'] as $operation) {
                try {
                    app(ManagesClients::class)->$operation($id, ...($operation === 'update' ? [new ClientChanges(name: 'Bad')] : []));
                    $this->fail('Unmanaged application accepted.');
                } catch (ModelNotFoundException) {
                    $this->assertSame('Host OAuth', $foreign->fresh()->name);
                    $this->assertFalse($foreign->fresh()->revoked);
                }
            }
        }
    }

    public function test_artisan_lists_updates_and_revokes_using_the_public_contract(): void
    {
        $id = $this->create();
        $this->assertSame(0, Artisan::call('sso:clients', ['--json' => true]));
        $this->assertSame($id, json_decode(Artisan::output(), true)['data'][0]['id']);
        $this->assertSame(0, Artisan::call('sso:client-update', ['client' => $id, '--name' => 'CLI name', '--restrict-users' => '1']));
        $this->assertSame('CLI name', app(ManagesClients::class)->find($id)->name);
        $this->assertTrue(app(ManagesClients::class)->find($id)->restrictUsers);
        $this->assertSame(1, Artisan::call('sso:client-update', ['client' => $id, '--first-party' => 'yes']));
        $this->assertSame(1, Artisan::call('sso:client-update', ['client' => $id, '--first-party' => '']));
        $this->assertSame(1, Artisan::call('sso:clients', ['--page' => 'bad']));
        $this->assertSame(1, Artisan::call('sso:client-update', ['client' => $id, '--require-pkce' => '0']));
        app(ManagesClients::class)->update($id, new ClientChanges(postLogoutRedirectUri: 'https://one.test/done'));
        $this->assertSame(0, Artisan::call('sso:client-update', ['client' => $id, '--post-logout' => '']));
        $this->assertSame([], app(ManagesClients::class)->find($id)->postLogoutRedirectUris);
        $this->assertSame(0, Artisan::call('sso:client-revoke', ['client' => $id]));
        $this->assertTrue(json_decode(Artisan::output(), true)['revoked']);
        $this->assertSame(0, Artisan::call('sso:clients', ['--status' => 'revoked', '--json' => true]));
        $this->assertSame(1, json_decode(Artisan::output(), true)['total']);
        $this->assertSame(1, Artisan::call('sso:client-revoke', ['client' => 'invalid']));
    }

    public function test_host_crud_owns_authorization_and_composes_read_update_and_revoke(): void
    {
        $id = $this->create();
        $user = CentralUser::create(['name' => 'Operator', 'email' => 'operator@example.test', 'password' => bcrypt('keep-password')]);
        $before = $user->fresh()->getAttributes();
        Gate::define('manage-sso-clients', fn (CentralUser $operator) => $operator->id === $user->id);
        Route::middleware('web')->get('/host/clients', function (ManagesClients $clients) {
            Gate::authorize('manage-sso-clients');

            return $clients->listing();
        });
        Route::middleware('web')->patch('/host/clients/{id}', function (Request $request, string $id, ManagesClients $clients) {
            Gate::authorize('manage-sso-clients');
            $input = $request->validate(['name' => 'required|string']);

            return response()->json($clients->update($id, new ClientChanges(name: $input['name'])));
        });
        Route::middleware('web')->post('/host/clients/{id}/revoke', function (string $id, ManagesClients $clients) {
            Gate::authorize('manage-sso-clients');

            return response()->json($clients->revoke($id));
        });
        $this->getJson('/host/clients')->assertForbidden();
        $this->patchJson('/host/clients/'.$id, ['name' => 'Unauthorized'])->assertForbidden();
        $this->postJson('/host/clients/'.$id.'/revoke')->assertForbidden();
        $this->actingAs($user, 'web')->getJson('/host/clients')->assertOk()->assertJsonPath('total', 1);
        $this->patchJson('/host/clients/'.$id, ['name' => 'Host name', 'public' => false, 'revoked' => true, 'require_pkce' => false])
            ->assertOk()->assertJson(['name' => 'Host name', 'public' => true, 'require_pkce' => true, 'revoked' => false]);
        $this->postJson('/host/clients/'.$id.'/revoke')->assertOk()->assertJson(['revoked' => true]);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->getJson('/sso/admin/clients')->assertNotFound();
    }
}
