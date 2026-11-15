<?php

namespace LaravelSso\Tests\Database;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use LaravelSso\Tests\TestCase;
use PHPUnit\Runner\Version;

final class EnvironmentTest extends TestCase
{
    public function test_real_engine_and_isolation_are_recorded_without_credentials(): void
    {
        $driver = DB::getDriverName();
        $this->assertContains($driver, ['mysql', 'pgsql']);
        $version = DB::selectOne('select version() as version')->version;
        $isolation = $driver === 'mysql' ? DB::selectOne('select @@transaction_isolation as isolation')->isolation
            : DB::selectOne('show transaction_isolation')->transaction_isolation;
        $expectedIsolation = getenv('SSO_TEST_ISOLATION') ?: ($driver === 'mysql' ? 'REPEATABLE READ' : 'READ COMMITTED');
        $this->assertSame(strtolower($expectedIsolation), strtolower(str_replace('-', ' ', $isolation)));
        $this->assertSame('sso_lab_test', DB::connection()->getDatabaseName());
        $collations = null;
        if ($driver === 'mysql') {
            $collations = ['connection' => DB::selectOne('select @@collation_connection as collation')->collation,
                'local_index' => DB::selectOne("select TABLE_COLLATION as collation from information_schema.TABLES where TABLE_SCHEMA = 'sso_lab_test' and TABLE_NAME = 'sso_local_sessions'")->collation];
            $this->assertSame('utf8mb4_unicode_ci', $collations['connection']);
            $this->assertSame('utf8mb4_bin', $collations['local_index']);
        }
        $fingerprint = require dirname(__DIR__, 2).'/tools/database-fingerprint.php';
        file_put_contents((getenv('SSO_LAB_RESULTS') ?: '/results').'/'.getenv('SSO_LAB_RUN').'-environment.json', json_encode([
            'checked_at_utc' => gmdate(DATE_ATOM), 'php' => PHP_VERSION, 'os' => PHP_OS_FAMILY,
            'driver' => $driver, 'database_version' => $version, 'isolation' => $isolation,
            'pcntl' => extension_loaded('pcntl'), 'pdo_client' => DB::connection()->getPdo()->getAttribute(\PDO::ATTR_CLIENT_VERSION),
            'source_sha256' => $fingerprint(dirname(__DIR__, 2)),
            'collations' => $collations,
            'laravel' => InstalledVersions::getPrettyVersion('laravel/framework'),
            'passport' => InstalledVersions::getPrettyVersion('laravel/passport'),
            'oauth2_server' => InstalledVersions::getPrettyVersion('league/oauth2-server'),
            'phpunit' => Version::id(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    }
}
