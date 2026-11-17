<?php

namespace LaravelSso\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MigrationTest extends TestCase
{
    public function test_server_schema_can_be_recreated_from_one_migration(): void
    {
        $files = glob(__DIR__.'/../packages/server/database/migrations/*.php');
        $this->assertCount(1, $files);
        $migration = require $files[0];
        $tables = ['sso_clients', 'sso_client_users', 'sso_sessions', 'sso_auth_contexts', 'sso_token_sessions', 'sso_logout_deliveries', 'sso_devices', 'sso_remember_tokens'];

        $migration->down();
        foreach ($tables as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $migration->up();
        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        DB::table('sso_clients')->insert(['client_id' => '00000000-0000-4000-8000-000000000001']);
        $this->assertSame(1, (int) DB::table('sso_clients')->value('require_pkce'));
        $this->assertSame(0, (int) DB::table('sso_clients')->value('restrict_users'));
        $this->assertTrue(Schema::hasIndex('sso_client_users', ['client_id', 'subject'], 'primary'));
        DB::table('sso_auth_contexts')->insert(['code_id' => 'code-one', 'sid' => 'session-one']);
        $context = DB::table('sso_auth_contexts')->first();
        $this->assertNull($context->nonce);
        $this->assertNull($context->claims_request);
        DB::table('sso_token_sessions')->insert(['token_id' => 'token-one', 'sid' => 'session-one', 'client_id' => '00000000-0000-4000-8000-000000000001']);
        $token = DB::table('sso_token_sessions')->first();
        $this->assertNull($token->code_id);
        $this->assertNull($token->claims_request);
        $this->assertTrue(Schema::hasIndex('sso_token_sessions', ['code_id']));
        $this->assertTrue(Schema::hasIndex('sso_logout_deliveries', ['sid', 'client_id'], 'unique'));
    }
}
