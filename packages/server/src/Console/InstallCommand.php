<?php

declare(strict_types=1);

namespace LaravelSso\Server\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use LaravelSso\Contracts\InstallationConfiguration;
use LaravelSso\Contracts\InstallationFiles;

final class InstallCommand extends Command
{
    protected $signature = 'sso:install-server {--configure : Configure SSO in the application environment} {--issuer= : Dedicated public issuer origin} {--allow-http : Allow HTTP outside production for development} {--headless : Disable the optional fallback login page} {--migrate : Run only Passport and SSO migrations} {--force : Allow explicit migrations in production} {--keys : Create missing signing keys}';

    protected $description = 'Install missing server configuration and the single initial migration without overwriting files';

    public function handle(InstallationFiles $files): int
    {
        try {
            if ($this->option('allow-http') && app()->environment('production')) {
                throw new \RuntimeException('HTTP is not allowed in production.');
            }
            $settings = [];
            if ($this->option('configure')) {
                $issuer = $this->option('issuer') ?: ($this->input->isInteractive() ? $this->ask('Public issuer URL', config('app.url')) : config('app.url'));
                $issuer = app(InstallationConfiguration::class)->url((string) $issuer, (bool) $this->option('allow-http'), true);
                $settings = ['SSO_SERVER_ENABLED' => 'true', 'SSO_ISSUER' => $issuer,
                    'SSO_SERVER_ALLOW_HTTP' => $this->option('allow-http') ? 'true' : 'false'];
                if ($this->option('headless')) {
                    $settings['SSO_SERVER_LOGIN_UI'] = 'false';
                }
            }
            $installed = $files->install(dirname(__DIR__, 2), config_path(), database_path('migrations'));
            if ($settings !== []) {
                app(InstallationConfiguration::class)->write($this->laravel->environmentFilePath(), $settings);
                $this->call('config:clear');
                $this->call('route:clear');
                $this->info('SSO environment configured; application keys, users and host routes preserved.');
            }
        } catch (\RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $this->info('Configuration and initial migration ready; existing files preserved.');
        if ($this->option('migrate')) {
            if (! Schema::hasTable('oauth_clients')) {
                $passport = dirname((new \ReflectionClass(Passport::class))->getFileName(), 2).'/database/migrations';
                if ($this->call('migrate', ['--path' => [$passport], '--realpath' => true, '--force' => (bool) $this->option('force')]) !== self::SUCCESS) {
                    return self::FAILURE;
                }
            }
            if ($this->call('migrate', ['--path' => [$installed['migration']], '--realpath' => true, '--force' => (bool) $this->option('force')]) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }
        if ($this->option('keys')) {
            $oauthPrivate = config('passport.private_key');
            $oauthPublic = config('passport.public_key');
            if (! $oauthPrivate && ! $oauthPublic && ! file_exists(Passport::keyPath('oauth-private.key')) && ! file_exists(Passport::keyPath('oauth-public.key'))
                && $this->call('passport:keys') !== self::SUCCESS) {
                return self::FAILURE;
            }
            $private = config('sso-server.private_key');
            $public = config('sso-server.public_keys')[config('sso-server.signing_kid')] ?? null;
            if (! is_string($private) || ! is_string($public) || ! (file_exists($private) && file_exists($public))) {
                if ($this->call('sso:keys') !== self::SUCCESS) {
                    return self::FAILURE;
                }
            }
        }
        $this->line('Run sso:doctor-server. Login uses your host route when available, otherwise /sso/auth/login; JSON is available at /sso/auth/session. Configure the Laravel scheduler for logout delivery and retention.');

        return self::SUCCESS;
    }
}
