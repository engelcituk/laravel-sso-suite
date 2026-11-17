<?php

namespace LaravelSso\Tests;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use LaravelSso\Contracts\InstallationConfiguration;
use LaravelSso\Tests\Fixtures\LocalUser;

final class AgnosticInstallerTest extends TestCase
{
    private function isolated(callable $run): void
    {
        $directory = dirname(__DIR__).'/.runtime/agnostic-install-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory.'/database/migrations');
        file_put_contents($directory.'/.env', "APP_NAME=ExistingHost\nAPP_KEY=keep-this-key\nDB_DATABASE=keep-database\nSSO_CLIENT_ENABLED=false\n");
        $paths = [config_path(), database_path(), $this->app->environmentPath()];
        $this->app->useConfigPath($directory.'/config');
        $this->app->useDatabasePath($directory.'/database');
        $this->app->useEnvironmentPath($directory);
        file_put_contents($directory.'/database/migrations/2099_01_01_000000_host_probe.php', '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { throw new \\RuntimeException("Host migration must not run"); } };');
        try {
            $run($directory);
        } finally {
            $this->app->useConfigPath($paths[0]);
            $this->app->useDatabasePath($paths[1]);
            $this->app->useEnvironmentPath($paths[2]);
            File::deleteDirectory($directory);
        }
    }

    public function test_server_setup_configures_only_sso_and_skips_pending_host_migrations(): void
    {
        $user = LocalUser::create(['email' => 'existing@example.test', 'password' => bcrypt('password'), 'remember_token' => 'unchanged']);
        $before = $user->fresh()->getAttributes();
        $this->isolated(function ($directory): void {
            $args = ['--configure' => true, '--issuer' => 'https://identity.test/', '--migrate' => true, '--keys' => true];
            $this->assertSame(0, Artisan::call('sso:install-server', $args));
            $data = Dotenv::parse(file_get_contents($directory.'/.env'));
            $this->assertSame('https://identity.test', $data['SSO_ISSUER']);
            $this->assertSame('true', $data['SSO_SERVER_ENABLED']);
            $this->assertSame('keep-this-key', $data['APP_KEY']);
            $this->assertSame('keep-database', $data['DB_DATABASE']);
            $this->assertCount(1, glob($directory.'/database/migrations/*create_sso_tables.php'));
            $this->assertSame(0, Artisan::call('sso:install-server', $args));
            $this->assertSame(0, DB::table('migrations')->where('migration', '2099_01_01_000000_host_probe')->count());
        });
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_client_setup_escapes_secret_and_preserves_model_credentials_and_environment(): void
    {
        $user = LocalUser::create(['email' => 'existing@example.test', 'password' => bcrypt('password')]);
        $before = $user->fresh()->getAttributes();
        $secret = 'private#"value${DO_NOT_EXPAND}\\tail';
        config(['sso-client.client_secret' => $secret]);
        $this->isolated(function ($directory) use ($secret): void {
            $args = ['--configure' => true, '--issuer' => 'https://identity.test', '--callback' => 'https://one.test/sso/callback',
                '--client-id' => 'private-client-id', '--migrate' => true];
            $this->assertSame(0, Artisan::call('sso:install-client', $args));
            $this->assertStringNotContainsString($secret, Artisan::output());
            $data = Dotenv::parse(file_get_contents($directory.'/.env'));
            $this->assertSame($secret, $data['SSO_CLIENT_SECRET']);
            $this->assertSame('true', $data['SSO_CLIENT_ENABLED']);
            $this->assertSame('keep-this-key', $data['APP_KEY']);
            $this->assertSame('https://identity.test/oidc/session', $data['SSO_SESSION_STATUS_URL']);
            $this->assertSame(0, Artisan::call('sso:install-client', $args));
            $this->assertCount(1, glob($directory.'/database/migrations/*create_sso_local_sessions.php'));
            $this->assertSame(0, DB::table('migrations')->where('migration', '2099_01_01_000000_host_probe')->count());
        });
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_http_and_bad_issuer_fail_before_environment_changes(): void
    {
        $this->isolated(function ($directory): void {
            $before = file_get_contents($directory.'/.env');
            $this->assertSame(1, Artisan::call('sso:install-server', ['--configure' => true, '--issuer' => 'http://identity.test']));
            $this->assertSame($before, file_get_contents($directory.'/.env'));
            $this->assertSame(1, Artisan::call('sso:install-server', ['--configure' => true, '--issuer' => 'https://identity.test/path']));
            $this->assertSame($before, file_get_contents($directory.'/.env'));
            $environment = $this->app['env'];
            try {
                $this->app['env'] = 'production';
                $this->assertSame(1, Artisan::call('sso:install-server', ['--allow-http' => true]));
                $this->assertSame($before, file_get_contents($directory.'/.env'));
            } finally {
                $this->app['env'] = $environment;
            }
        });
    }

    public function test_environment_writer_rejects_duplicate_or_non_sso_keys_without_rewriting_host_file(): void
    {
        $this->isolated(function ($directory): void {
            $path = $directory.'/.env';
            file_put_contents($path, "APP_KEY=keep\nSSO_ISSUER=one\nSSO_ISSUER=two\n");
            $before = file_get_contents($path);
            foreach ([['SSO_ISSUER' => 'https://id.test'], ['APP_KEY' => 'replace'], ['SSO_ISSUER' => "bad\nINJECT=true"]] as $values) {
                try {
                    app(InstallationConfiguration::class)->write($path, $values);
                    $this->fail('Unsafe configuration accepted.');
                } catch (\RuntimeException) {
                    $this->assertSame($before, file_get_contents($path));
                }
            }
        });
    }

    public function test_explicit_trust_registration_preserves_other_environment_values_and_existing_destinations(): void
    {
        $this->isolated(function ($directory): void {
            $args = ['name' => 'Existing client', 'redirect' => 'https://three.test/sso/callback',
                '--backchannel' => 'https://three.test/sso/backchannel-logout', '--trust-backchannel' => true];
            $this->assertSame(0, Artisan::call('sso:client', $args));
            $data = Dotenv::parse(file_get_contents($directory.'/.env'));
            $this->assertSame('keep-this-key', $data['APP_KEY']);
            $this->assertContains('https://three.test/sso/backchannel-logout', config('sso-server.backchannel_allowed_urls'));
            $this->assertStringContainsString('https://three.test/sso/backchannel-logout', $data['SSO_BACKCHANNEL_ALLOWED_URLS']);
        });
    }
}
