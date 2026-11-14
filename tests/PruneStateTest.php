<?php

namespace LaravelSso\Tests;

use Illuminate\Support\Facades\DB;
use LaravelSso\Client\PruneState as ClientState;
use LaravelSso\Server\PruneState as ServerState;

final class PruneStateTest extends TestCase
{
    public function test_server_cleanup_preserves_pending_deliveries_recent_hints_active_tokens_and_host_data(): void
    {
        $client = '00000000-0000-4000-8000-000000000001';
        foreach (['old', 'pending', 'recent-hint', 'active-token', 'active-session'] as $sid) {
            DB::table('sso_sessions')->insert(['sid' => $sid, 'subject' => 'subject', 'auth_time' => time() - 400000,
                'last_seen' => time() - 400000, 'expires_at' => match ($sid) {
                    'recent-hint' => time() - 86401, 'active-session' => time() + 100, default => time() - 300000,
                }, 'revoked' => false]);
            DB::table('oauth_access_tokens')->insert(['id' => $sid, 'user_id' => 1, 'client_id' => $client,
                'scopes' => '[]', 'revoked' => false, 'expires_at' => gmdate('Y-m-d H:i:s',
                    $sid === 'active-token' ? time() + 3600 : time() - 300000)]);
            DB::table('sso_token_sessions')->insert(['token_id' => $sid, 'sid' => $sid, 'client_id' => $client]);
        }
        DB::table('oauth_access_tokens')->insert(['id' => 'host-owned', 'user_id' => 1, 'client_id' => $client,
            'scopes' => '[]', 'revoked' => true, 'expires_at' => gmdate('Y-m-d H:i:s', time() - 300000)]);
        DB::table('sso_logout_deliveries')->insert(['id' => 'pending-delivery', 'sid' => 'pending', 'client_id' => $client,
            'next_attempt' => time() - 300000]);
        DB::table('sso_logout_deliveries')->insert(['id' => 'old-success', 'sid' => 'old', 'client_id' => $client,
            'next_attempt' => time() - 300000, 'delivered_at' => time() - 300000]);
        foreach (['old-code', 'recent-code', 'host-code'] as $code) {
            DB::table('oauth_auth_codes')->insert(['id' => $code, 'user_id' => 1, 'client_id' => $client,
                'scopes' => '[]', 'revoked' => false, 'expires_at' => gmdate('Y-m-d H:i:s',
                    $code === 'recent-code' ? time() + 60 : time() - 300000)]);
            if ($code !== 'host-code') {
                DB::table('sso_auth_contexts')->insert(['code_id' => $code, 'sid' => 'old']);
            }
        }
        $this->assertSame(['deliveries' => 1, 'contexts' => 1, 'codes' => 1, 'bindings' => 1, 'tokens' => 1, 'sessions' => 1, 'remember_tokens' => 0, 'devices' => 0],
            app(ServerState::class)->prune(1));
        $this->assertSame(['active-session', 'active-token', 'pending', 'recent-hint'], DB::table('sso_sessions')->orderBy('sid')->pluck('sid')->all());
        $this->assertTrue(DB::table('oauth_access_tokens')->where('id', 'host-owned')->exists());
        $this->assertTrue(DB::table('oauth_auth_codes')->where('id', 'host-code')->exists());
        $this->assertTrue(DB::table('sso_auth_contexts')->where('code_id', 'recent-code')->exists());
        $this->assertTrue(DB::table('sso_logout_deliveries')->where('id', 'pending-delivery')->whereNull('delivered_at')->exists());
        $this->assertSame(0, array_sum(app(ServerState::class)->prune()));
    }

    public function test_client_cleanup_preserves_active_and_unknown_expiry_indexes_and_the_replay_window(): void
    {
        config(['sso-client.receipt_retention' => 0, 'sso-client.max_token_age' => 300, 'sso-client.clock_skew' => 30]);
        foreach (['expired', 'revoked', 'active', 'unknown'] as $name) {
            DB::table('sso_local_sessions')->insert(['session_hash' => hash('sha256', $name), 'issuer' => 'https://identity.test',
                'subject' => 'subject', 'sid' => $name, 'revoked' => $name === 'revoked',
                'expires_at' => match ($name) {
                    'expired' => time() - 1, 'unknown' => null, default => time() + 3600
                }]);
        }
        foreach (['old' => time() - 400, 'in-window' => time() - 320, 'recent' => time()] as $name => $received) {
            DB::table('sso_logout_receipts')->insert(['token_hash' => hash('sha256', $name), 'received_at' => $received]);
        }
        $this->assertSame(['sessions' => 2, 'receipts' => 1], app(ClientState::class)->prune());
        $this->assertSame(['active', 'unknown'], DB::table('sso_local_sessions')->orderBy('sid')->pluck('sid')->all());
        $this->assertSame(2, DB::table('sso_logout_receipts')->count());
        $this->assertSame(['sessions' => 0, 'receipts' => 0], app(ClientState::class)->prune());
    }

    public function test_cleanup_commands_report_counts_and_respect_batch_limits(): void
    {
        for ($i = 0; $i < 3; $i++) {
            DB::table('sso_local_sessions')->insert(['session_hash' => hash('sha256', (string) $i), 'issuer' => 'https://identity.test',
                'subject' => 'subject', 'sid' => 'revoked', 'revoked' => true]);
        }
        $this->artisan('sso:prune-client', ['--limit' => 1])->expectsOutput('{"sessions":1,"receipts":0}')->assertSuccessful();
        $this->assertSame(2, DB::table('sso_local_sessions')->count());
        $this->artisan('sso:prune-server')->expectsOutput('{"deliveries":0,"contexts":0,"codes":0,"bindings":0,"tokens":0,"sessions":0,"remember_tokens":0,"devices":0}')->assertSuccessful();
    }
}
