<?php

namespace LaravelSso\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use LaravelSso\Client\SsoClientServiceProvider;
use LaravelSso\Contracts\InstallationFiles;
use LaravelSso\Server\Http\ProtocolController;
use LaravelSso\Server\SigningKeys;
use LaravelSso\Server\SsoServerServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;

final class OperationsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = dirname(__DIR__).'/.runtime/operations-tests/'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public static function packages(): array
    {
        return ['server' => ['server', 'create_sso_tables.php'], 'client' => ['client', 'create_sso_local_sessions.php']];
    }

    #[DataProvider('packages')]
    public function test_install_command_is_repeatable_preserves_configuration_and_migration_timestamp(string $role, string $suffix): void
    {
        $oldConfig = config_path();
        $oldDatabase = database_path();
        $this->app->useConfigPath($this->directory.'/config');
        $this->app->useDatabasePath($this->directory.'/database');
        $before = DB::table('migrations')->count();
        try {
            $this->assertSame(0, Artisan::call('sso:install-'.$role));
            $config = config_path('sso-'.$role.'.php');
            file_put_contents($config, '<?php return ["operator_setting" => true];');
            $original = glob(database_path('migrations/*_'.$suffix))[0];
            $renamed = database_path('migrations/2020_01_01_000000_'.$suffix);
            rename($original, $renamed);
            $hash = hash_file('sha256', $renamed);
            $this->assertSame(0, Artisan::call('sso:install-'.$role));
            $this->assertSame(0, Artisan::call('sso:install-'.$role));
            $this->assertSame([$renamed], glob(database_path('migrations/*.php')));
            $this->assertSame($hash, hash_file('sha256', $renamed));
            $this->assertSame('<?php return ["operator_setting" => true];', file_get_contents($config));
            $this->assertSame($before, DB::table('migrations')->count());
        } finally {
            $this->app->useConfigPath($oldConfig);
            $this->app->useDatabasePath($oldDatabase);
        }
    }

    #[DataProvider('packages')]
    public function test_duplicate_migrations_fail_before_writing_configuration(string $role, string $suffix): void
    {
        $migrations = $this->directory.'/migrations';
        File::ensureDirectoryExists($migrations);
        file_put_contents($migrations.'/2020_01_01_000000_'.$suffix, '<?php');
        file_put_contents($migrations.'/2021_01_01_000000_'.$suffix, '<?php');
        try {
            app(InstallationFiles::class)->install(dirname(__DIR__).'/packages/'.$role, $this->directory.'/config', $migrations);
            $this->fail('Duplicate initial migrations accepted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Duplicate', $error->getMessage());
            $this->assertDirectoryDoesNotExist($this->directory.'/config');
        }
    }

    private function report(string $command, array $options = [], int $expected = 0): array
    {
        $this->assertSame($expected, Artisan::call($command, $options + ['--json' => true]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $report['version']);

        return array_column($report['checks'], 'status', 'code');
    }

    public function test_both_doctors_report_current_schema_without_http_or_database_writes(): void
    {
        Http::preventStrayRequests();
        $before = DB::table('migrations')->count();
        $this->assertNotContains('fail', $this->report('sso:doctor-server'));
        $this->assertNotContains('fail', $this->report('sso:doctor', ['--offline' => true]));
        $this->assertSame($before, DB::table('migrations')->count());
        Http::assertNothingSent();
    }

    public function test_client_online_doctor_checks_actual_jwks_and_rejects_empty_and_symmetric_sets(): void
    {
        $metadata = app(ProtocolController::class)->discovery()->getData(true);
        Http::fake([
            'https://identity.test/.well-known/openid-configuration' => Http::response($metadata),
            'https://identity.test/oidc/jwks' => function () use (&$keys) {
                return Http::response($keys);
            },
        ]);
        foreach ([app(SigningKeys::class)->jwks(), ['keys' => []], ['keys' => [['kty' => 'oct', 'kid' => 'secret', 'alg' => 'HS256', 'k' => base64_encode('private-secret')]]]] as $index => $keys) {
            $checks = $this->report('sso:doctor', [], $index === 0 ? 0 : 1);
            $this->assertSame($index === 0 ? 'pass' : 'fail', $checks['discovery_jwks']);
            $this->assertStringNotContainsString('private-secret', Artisan::output());
        }
    }

    public function test_doctors_collect_multiple_failures_and_do_not_echo_secrets_or_exceptions(): void
    {
        config(['sso-client.client_secret' => 'private-client-secret', 'sso-client.issuer' => 'https://user:private-password@untrusted.test',
            'sso-client.redirect_uri' => 'http://untrusted.test/callback#private-fragment', 'sso-client.client_id' => '',
            'sso-server.private_key' => $this->directory.'/absent-private-key.pem', 'sso-server.session_idle' => 0]);
        $server = $this->report('sso:doctor-server', [], 1);
        $client = $this->report('sso:doctor', ['--offline' => true], 1);
        $this->assertSame('fail', $server['signing_keys']);
        $this->assertSame('fail', $server['retention']);
        $this->assertSame('fail', $client['configuration']);
        $this->assertSame('fail', $client['trusted_urls']);
        $this->assertStringNotContainsString('private-', Artisan::output());
    }

    public function test_client_doctor_detects_missing_unique_identity_index(): void
    {
        Schema::table('users', fn ($table) => $table->dropUnique(['sso_issuer', 'sso_subject']));
        $this->assertSame('fail', $this->report('sso:doctor', ['--offline' => true], 1)['identity']);
    }

    public function test_server_doctor_detects_overdue_outbox_without_delivering_it(): void
    {
        DB::table('sso_logout_deliveries')->insert(['id' => 'pending', 'sid' => 'sid', 'client_id' => config('sso-client.client_id'), 'next_attempt' => time() - 600]);
        Http::preventStrayRequests();
        $this->assertSame('fail', $this->report('sso:doctor-server', [], 1)['outbox']);
        $this->assertSame(0, (int) DB::table('sso_logout_deliveries')->value('attempts'));
        Http::assertNothingSent();
    }

    public function test_doctors_detect_missing_tables_without_running_migrations(): void
    {
        Schema::drop('sso_devices');
        Schema::drop('sso_logout_receipts');
        $this->assertSame('fail', $this->report('sso:doctor-server', [], 1)['schema']);
        $this->assertSame('fail', $this->report('sso:doctor', ['--offline' => true], 1)['schema']);
        $this->assertFalse(Schema::hasTable('sso_devices'));
        $this->assertFalse(Schema::hasTable('sso_logout_receipts'));
    }

    public function test_installer_keys_option_preserves_existing_key_pair_on_repetition(): void
    {
        $oldConfig = config_path();
        $oldDatabase = database_path();
        $this->app->useConfigPath($this->directory.'/config');
        $this->app->useDatabasePath($this->directory.'/database');
        $before = hash_file('sha256', config('sso-server.private_key'));
        try {
            $this->assertSame(0, Artisan::call('sso:install-server', ['--keys' => true]));
            $this->assertSame(0, Artisan::call('sso:install-server', ['--keys' => true]));
            $this->assertSame($before, hash_file('sha256', config('sso-server.private_key')));
        } finally {
            $this->app->useConfigPath($oldConfig);
            $this->app->useDatabasePath($oldDatabase);
        }
    }

    public function test_explicit_migrate_runs_pending_package_schema_once(): void
    {
        $oldConfig = config_path();
        $oldDatabase = database_path();
        $this->app->useConfigPath($this->directory.'/config');
        $this->app->useDatabasePath($this->directory.'/database');
        // These two client tables have no host data in this isolated test database.
        Schema::drop('sso_local_sessions');
        Schema::drop('sso_logout_receipts');
        DB::table('migrations')->where('migration', 'like', '%create_sso_local_sessions')->delete();
        try {
            $this->assertSame(0, Artisan::call('sso:install-client', ['--migrate' => true]));
            $this->assertTrue(Schema::hasTable('sso_local_sessions'));
            $this->assertTrue(Schema::hasTable('sso_logout_receipts'));
            $this->assertSame(0, Artisan::call('sso:install-client', ['--migrate' => true]));
            $this->assertSame(1, DB::table('migrations')->where('migration', 'like', '%create_sso_local_sessions')->count());
        } finally {
            $this->app->useConfigPath($oldConfig);
            $this->app->useDatabasePath($oldDatabase);
        }
    }

    public function test_installer_generates_new_keys_once_and_doctor_detects_mismatched_pairs(): void
    {
        $oldConfig = config_path();
        $oldDatabase = database_path();
        $this->app->useConfigPath($this->directory.'/config');
        $this->app->useDatabasePath($this->directory.'/database');
        config(['sso-server.private_key' => $this->directory.'/keys/private.pem', 'sso-server.public_keys' => ['initial' => $this->directory.'/keys/public.pem']]);
        try {
            $this->assertSame(0, Artisan::call('sso:install-server', ['--keys' => true]));
            $hash = hash_file('sha256', config('sso-server.private_key'));
            $this->assertSame(0, Artisan::call('sso:install-server', ['--keys' => true]));
            $this->assertSame($hash, hash_file('sha256', config('sso-server.private_key')));
            $this->assertSame('pass', $this->report('sso:doctor-server')['signing_keys']);
            config(['sso-server.public_keys' => ['initial' => __DIR__.'/.keys/public.pem']]);
            $this->assertSame('fail', $this->report('sso:doctor-server', [], 1)['signing_keys']);
        } finally {
            $this->app->useConfigPath($oldConfig);
            $this->app->useDatabasePath($oldDatabase);
        }
    }

    public function test_console_diagnosis_can_boot_with_invalid_runtime_configuration_but_web_still_rejects_it(): void
    {
        $argv = $_SERVER['argv'];
        config(['sso-server.issuer' => 'invalid-origin', 'session.driver' => 'cookie']);
        try {
            $_SERVER['argv'] = ['artisan', 'sso:doctor-server'];
            (new SsoServerServiceProvider($this->app))->boot();
            $this->assertSame('fail', $this->report('sso:doctor-server', [], 1)['issuer']);
            $_SERVER['argv'] = ['artisan', 'sso:doctor'];
            (new SsoClientServiceProvider($this->app))->boot();
            $this->assertSame('fail', $this->report('sso:doctor', ['--offline' => true], 1)['session']);
            $_SERVER['argv'] = ['artisan', 'route:list'];
            $this->expectException(\RuntimeException::class);
            (new SsoServerServiceProvider($this->app))->boot();
        } finally {
            $_SERVER['argv'] = $argv;
        }
    }
}
