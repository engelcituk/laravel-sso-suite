<?php

namespace LaravelSso\Tests\Database;

use Firebase\JWT\JWT;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\ClientRepository;
use LaravelSso\Client\EloquentProvisioner;
use LaravelSso\Contracts\Identity;
use LaravelSso\Server\GlobalSessions;
use LaravelSso\Server\Http\ProtocolController;
use LaravelSso\Server\LogoutDeliveries;
use LaravelSso\Server\PruneState;
use LaravelSso\Server\SigningKeys;
use LaravelSso\Tests\Fixtures\CentralUser;
use LaravelSso\Tests\Fixtures\LocalUser;
use LaravelSso\Tests\TestCase;

final class ConcurrencyTest extends TestCase
{
    private function authorization(): array
    {
        $user = CentralUser::create(['name' => 'Concurrent identity', 'email' => 'concurrency@example.test', 'password' => bcrypt('test')]);
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Concurrency RP', ['https://one.test/callback'], false);
        DB::table('sso_clients')->insert(['client_id' => $client->id, 'auto_approve' => true,
            'backchannel_logout_uri' => 'https://one.test/backchannel']);
        config(['sso-server.backchannel_allowed_urls' => ['https://one.test/backchannel']]);
        $verifier = str_repeat('v', 64);
        $response = $this->actingAs($user, 'web')->get('/oidc/authorize?'.http_build_query([
            'client_id' => $client->id, 'response_type' => 'code', 'redirect_uri' => 'https://one.test/callback', 'scope' => 'openid',
            'code_challenge_method' => 'S256', 'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', $verifier, true)),
        ]))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return [['grant_type' => 'authorization_code', 'client_id' => $client->id, 'code' => $query['code'],
            'code_verifier' => $verifier, 'redirect_uri' => 'https://one.test/callback'], DB::table('sso_auth_contexts')->value('sid')];
    }

    public function test_eight_simultaneous_exchanges_consume_one_code_and_revoke_its_token_on_replay(): void
    {
        [$exchange] = $this->authorization();
        $results = Parallel::run(8, function (int $index, string $directory) use ($exchange): int {
            $paused = false;
            DB::listen(function (QueryExecuted $query) use (&$paused, $directory, $index): void {
                if (! $paused && str_contains($query->sql, 'oauth_auth_codes') && str_starts_with($query->sql, 'select')
                    && str_contains($query->sql, 'exists')) {
                    $paused = true;
                    Parallel::barrier($directory, 'code-read', $index, 8);
                }
            });

            return $this->postJson('/oidc/token', $exchange)->getStatusCode();
        });
        sort($results);
        $this->assertSame([200, 400, 400, 400, 400, 400, 400, 400], $results);
        $this->assertSame(1, DB::table('oauth_access_tokens')->count());
        $this->assertSame(1, DB::table('sso_token_sessions')->count());
        $this->assertTrue((bool) DB::table('oauth_auth_codes')->value('revoked'));
        $this->assertTrue((bool) DB::table('oauth_access_tokens')->value('revoked'));
    }

    public function test_parallel_provisioning_in_transactions_resolves_one_issuer_subject_without_email_linking(): void
    {
        config(['sso-client.provision' => 'automatic']);
        LocalUser::create(['sso_issuer' => 'https://other.test', 'sso_subject' => 'other', 'email' => 'same@example.test']);
        $results = Parallel::run(8, function (int $index, string $directory): string {
            return DB::transaction(function () use ($index, $directory): string {
                // Establish an old snapshot on MySQL REPEATABLE READ before another insert commits.
                DB::table('users')->count();
                $paused = false;
                DB::listen(function (QueryExecuted $query) use (&$paused, $index, $directory): void {
                    if (! $paused && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'sso_issuer')) {
                        $paused = true;
                        Parallel::barrier($directory, 'identity-read', $index, 8);
                    }
                });
                $user = app(EloquentProvisioner::class)->provision(new Identity('https://identity.test', 'shared', 'global', time(),
                    ['name' => 'Concurrent identity', 'email' => 'same@example.test', 'is_admin' => true]));

                return (string) $user->getAuthIdentifier();
            }, 10);
        });
        $this->assertCount(1, array_unique($results));
        $this->assertSame(2, LocalUser::count());
        $this->assertSame(1, LocalUser::where('sso_issuer', 'https://identity.test')->where('sso_subject', 'shared')->count());
        $this->assertArrayNotHasKey('is_admin', LocalUser::find($results[0])->getAttributes());
    }

    public function test_exchange_waiting_after_token_insert_cannot_outlive_committed_revocation(): void
    {
        [$exchange, $sid] = $this->authorization();
        $results = Parallel::run(2, function (int $index, string $directory) use ($exchange, $sid): int {
            if ($index === 1) {
                Parallel::wait($directory.'/token-inserted');
                app(GlobalSessions::class)->revoke($sid);
                touch($directory.'/revocation-committed');

                return 0;
            }
            DB::listen(function (QueryExecuted $query) use ($directory): void {
                if (str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'oauth_access_tokens')) {
                    touch($directory.'/token-inserted');
                    Parallel::wait($directory.'/revocation-committed');
                }
            });

            return $this->postJson('/oidc/token', $exchange)->getStatusCode();
        });
        $this->assertSame([400, 0], $results);
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
        $this->assertSame(0, DB::table('sso_token_sessions')->count());
        $this->assertTrue((bool) DB::table('sso_sessions')->where('sid', $sid)->value('revoked'));
    }

    public function test_concurrent_revoke_is_idempotent_for_tokens_and_outbox(): void
    {
        [$exchange, $sid] = $this->authorization();
        $this->postJson('/oidc/token', $exchange)->assertOk();
        $results = Parallel::run(8, function () use ($sid): bool {
            app(GlobalSessions::class)->revoke($sid);

            return true;
        });
        $this->assertSame(array_fill(0, 8, true), $results);
        $this->assertSame(1, DB::table('sso_logout_deliveries')->where('sid', $sid)->count());
        $this->assertSame(0, DB::table('oauth_access_tokens')->where('revoked', false)->count());
        $this->assertTrue((bool) DB::table('sso_sessions')->where('sid', $sid)->value('revoked'));
    }

    public function test_revocation_with_an_older_snapshot_sees_a_newly_committed_token_and_queues_logout(): void
    {
        [$exchange, $sid] = $this->authorization();
        $results = Parallel::run(2, function (int $index, string $directory) use ($exchange, $sid): int {
            if ($index === 0) {
                Parallel::wait($directory.'/old-snapshot');
                $status = $this->postJson('/oidc/token', $exchange)->getStatusCode();
                touch($directory.'/token-committed');

                return $status;
            }

            return DB::transaction(function () use ($directory, $sid): int {
                DB::table('sso_token_sessions')->count();
                touch($directory.'/old-snapshot');
                Parallel::wait($directory.'/token-committed');
                app(GlobalSessions::class)->revoke($sid);

                return 0;
            }, 10);
        });
        $this->assertSame([200, 0], $results);
        $this->assertTrue((bool) DB::table('oauth_access_tokens')->value('revoked'));
        $this->assertSame(1, DB::table('sso_logout_deliveries')->where('sid', $sid)->count());
    }

    public function test_eight_workers_claim_each_due_delivery_once(): void
    {
        [$exchange, $sid] = $this->authorization();
        $this->postJson('/oidc/token', $exchange)->assertOk();
        app(GlobalSessions::class)->revoke($sid);
        $results = Parallel::run(8, function (int $index, string $directory): bool {
            Http::fake(function () use ($index, $directory) {
                file_put_contents($directory.'/http-'.$index, 'sent');
                usleep(50000);

                return Http::response([], 200);
            });

            return app(LogoutDeliveries::class)->deliverOne();
        });
        $this->assertSame(1, count(array_filter($results)));
        $this->assertSame(1, (int) DB::table('sso_logout_deliveries')->value('attempts'));
        $this->assertNotNull(DB::table('sso_logout_deliveries')->value('delivered_at'));
    }

    public function test_late_worker_cannot_overwrite_the_result_of_a_reclaimed_delivery(): void
    {
        [$exchange, $sid] = $this->authorization();
        $this->postJson('/oidc/token', $exchange)->assertOk();
        app(GlobalSessions::class)->revoke($sid);
        $results = Parallel::run(2, function (int $index, string $directory): bool {
            if ($index === 1) {
                Parallel::wait($directory.'/old-worker-sending');
                // Simulate a worker pause beyond its lease without a minute-long sleep.
                DB::table('sso_logout_deliveries')->update(['next_attempt' => time() - 1]);
                Http::fake(fn () => Http::response([], 503));
                $processed = app(LogoutDeliveries::class)->deliverOne();
                touch($directory.'/new-worker-finished');

                return $processed;
            }
            Http::fake(function () use ($directory) {
                touch($directory.'/old-worker-sending');
                Parallel::wait($directory.'/new-worker-finished');

                return Http::response([], 200);
            });

            return app(LogoutDeliveries::class)->deliverOne();
        });
        $this->assertSame([true, true], $results);
        $this->assertSame(2, (int) DB::table('sso_logout_deliveries')->value('attempts'));
        $this->assertNull(DB::table('sso_logout_deliveries')->value('delivered_at'));
        $this->assertGreaterThan(time(), (int) DB::table('sso_logout_deliveries')->value('next_attempt'));
        DB::table('sso_logout_deliveries')->update(['next_attempt' => time() - 1]);
        Http::fake(fn () => Http::response([], 200));
        $this->assertTrue(app(LogoutDeliveries::class)->deliverOne());
        $this->assertNotNull(DB::table('sso_logout_deliveries')->value('delivered_at'));
    }

    public function test_parallel_duplicate_logout_receipts_are_acknowledged_without_revoking_other_sessions(): void
    {
        $metadata = app(ProtocolController::class)->discovery()->getData(true);
        $keys = app(SigningKeys::class)->jwks();
        foreach (['target', 'other-device'] as $sid) {
            DB::table('sso_local_sessions')->insert(['session_hash' => hash('sha256', $sid), 'issuer' => 'https://identity.test',
                'subject' => 'shared', 'sid' => $sid, 'revoked' => false]);
        }
        $token = app(SigningKeys::class)->sign(['iss' => 'https://identity.test', 'aud' => config('sso-client.client_id'),
            'sub' => 'shared', 'sid' => 'target', 'iat' => time(), 'exp' => time() + 120, 'jti' => 'parallel-logout',
            'events' => ['http://schemas.openid.net/event/backchannel-logout' => (object) []]]);
        $results = Parallel::run(8, function () use ($metadata, $keys, $token): int {
            Http::fake(['https://identity.test/.well-known/openid-configuration' => Http::response($metadata),
                'https://identity.test/oidc/jwks' => Http::response($keys)]);

            return $this->post('/sso/backchannel-logout', ['logout_token' => $token])->getStatusCode();
        });
        $this->assertSame(array_fill(0, 8, 200), $results);
        $this->assertSame(1, DB::table('sso_logout_receipts')->count());
        $this->assertTrue((bool) DB::table('sso_local_sessions')->where('sid', 'target')->value('revoked'));
        $this->assertFalse((bool) DB::table('sso_local_sessions')->where('sid', 'other-device')->value('revoked'));
    }

    public function test_eight_workers_drain_sixteen_deliveries_without_duplicate_http_attempts(): void
    {
        $client = '00000000-0000-4000-8000-000000000001';
        DB::table('sso_clients')->insert(['client_id' => $client, 'backchannel_logout_uri' => 'https://one.test/backchannel']);
        config(['sso-server.backchannel_allowed_urls' => ['https://one.test/backchannel']]);
        for ($i = 0; $i < 16; $i++) {
            DB::table('sso_sessions')->insert(['sid' => 'session-'.$i, 'subject' => 'subject',
                'auth_time' => time(), 'last_seen' => time(), 'expires_at' => time() + 100, 'revoked' => true]);
            DB::table('sso_logout_deliveries')->insert(['id' => hash('sha256', (string) $i),
                'sid' => 'session-'.$i, 'client_id' => $client, 'next_attempt' => time()]);
        }
        $results = Parallel::run(8, function (int $index, string $directory): array {
            Http::fake(function ($request) use ($index, $directory) {
                $claims = json_decode(JWT::urlsafeB64Decode(explode('.', $request['logout_token'])[1]), true, flags: JSON_THROW_ON_ERROR);
                // Separate files avoid concurrent append semantics on Docker Desktop's
                // Windows bind mount. Every attempt remains independently countable.
                file_put_contents($directory.'/http-attempt-'.$index.'-'.bin2hex(random_bytes(6)), $claims['jti']);
                usleep(20000);

                return Http::response([], 200);
            });
            $count = 0;
            while (app(LogoutDeliveries::class)->deliverOne()) {
                $count++;
            }

            return ['count' => $count, 'directory' => $directory];
        });
        $this->assertSame(16, array_sum(array_column($results, 'count')));
        $this->assertSame(16, DB::table('sso_logout_deliveries')->whereNotNull('delivered_at')->where('attempts', 1)->count());
        $calls = array_map('file_get_contents', glob($results[0]['directory'].'/http-attempt-*'));
        $this->assertCount(16, $calls);
        $this->assertCount(16, array_unique($calls));
    }

    public function test_parallel_pruners_delete_each_expired_session_once_and_preserve_pending_work(): void
    {
        $client = '00000000-0000-4000-8000-000000000001';
        for ($i = 0; $i < 16; $i++) {
            DB::table('sso_sessions')->insert(['sid' => 'expired-'.$i, 'subject' => 'subject', 'auth_time' => time() - 400000,
                'last_seen' => time() - 400000, 'expires_at' => time() - 300000, 'revoked' => true]);
            DB::table('sso_local_sessions')->insert(['session_hash' => hash('sha256', (string) $i), 'issuer' => 'https://identity.test',
                'subject' => 'subject', 'sid' => 'expired-'.$i, 'revoked' => true]);
            DB::table('sso_logout_receipts')->insert(['token_hash' => hash('sha256', (string) $i), 'received_at' => time() - 300000]);
        }
        DB::table('sso_logout_deliveries')->insert(['id' => 'pending', 'sid' => 'expired-0', 'client_id' => $client, 'next_attempt' => time()]);
        $results = Parallel::run(8, function (): array {
            return ['server' => app(PruneState::class)->prune(),
                'client' => app(\LaravelSso\Client\PruneState::class)->prune()];
        });
        $this->assertSame(15, array_sum(array_map(fn ($result) => $result['server']['sessions'], $results)));
        $this->assertSame(16, array_sum(array_map(fn ($result) => $result['client']['sessions'], $results)));
        $this->assertSame(16, array_sum(array_map(fn ($result) => $result['client']['receipts'], $results)));
        $this->assertSame(1, DB::table('sso_sessions')->count());
        $this->assertSame(1, DB::table('sso_logout_deliveries')->whereNull('delivered_at')->count());
    }
}
