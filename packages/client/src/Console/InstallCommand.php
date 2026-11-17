<?php

declare(strict_types=1);

namespace LaravelSso\Client\Console;

use Illuminate\Console\Command;
use LaravelSso\Contracts\InstallationConfiguration;
use LaravelSso\Contracts\InstallationFiles;

final class InstallCommand extends Command
{
    protected $signature = 'sso:install-client {--configure : Configure SSO in the application environment} {--issuer= : Identity server origin} {--callback= : Public callback URL} {--client-id= : Registered client ID} {--allow-http : Allow HTTP outside production for development} {--migrate : Run only the SSO client migration} {--force : Allow explicit migrations in production}';

    protected $description = 'Install missing client configuration and the single initial migration without overwriting files';

    public function handle(InstallationFiles $files): int
    {
        try {
            if ($this->option('allow-http') && app()->environment('production')) {
                throw new \RuntimeException('HTTP is not allowed in production.');
            }
            $settings = [];
            if ($this->option('configure')) {
                $configuration = app(InstallationConfiguration::class);
                $issuer = $this->option('issuer') ?: ($this->input->isInteractive() ? $this->ask('Identity server URL') : null);
                $callback = $this->option('callback') ?: ($this->input->isInteractive() ? $this->ask('Public callback URL', rtrim(config('app.url'), '/').'/sso/callback') : rtrim(config('app.url'), '/').'/sso/callback');
                $clientId = $this->option('client-id') ?: (config('sso-client.client_id') ?: ($this->input->isInteractive() ? $this->ask('Client ID') : null));
                $secret = config('sso-client.client_secret') ?: ($this->input->isInteractive() ? $this->secret('Client secret (not displayed)') : null);
                if (! is_string($clientId) || $clientId === '' || ! is_string($secret) || $secret === '') {
                    throw new \RuntimeException('A registered client ID and secret are required; supply the secret through the prompt or SSO_CLIENT_SECRET.');
                }
                $issuer = $configuration->url((string) $issuer, (bool) $this->option('allow-http'), true);
                $callback = $configuration->url((string) $callback, (bool) $this->option('allow-http'));
                $settings = ['SSO_CLIENT_ENABLED' => 'true', 'SSO_ISSUER' => $issuer,
                    'SSO_CLIENT_ID' => $clientId, 'SSO_CLIENT_SECRET' => $secret,
                    'SSO_REDIRECT_URI' => $callback, 'SSO_SESSION_STATUS_URL' => $issuer.'/oidc/session',
                    'SSO_CLIENT_ALLOW_HTTP' => $this->option('allow-http') ? 'true' : 'false'];
            }
            $installed = $files->install(dirname(__DIR__, 2), config_path(), database_path('migrations'));
            if ($settings !== []) {
                app(InstallationConfiguration::class)->write($this->laravel->environmentFilePath(), $settings);
                $this->call('config:clear');
                $this->call('route:clear');
                $this->info('SSO environment configured; existing user model, credentials and host routes preserved.');
            }
        } catch (\RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $this->info('Configuration and initial migration ready; existing files preserved.');
        if ($this->option('migrate')) {
            if ($this->call('migrate', ['--path' => [$installed['migration']], '--realpath' => true, '--force' => (bool) $this->option('force')]) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }
        $this->line('SessionGuard and user model are discovered from Laravel Auth. Conventional web auth routes are integrated; custom routes use sso.local / sso.session.');
        $this->line('New configuration uses greenfield provisioning: the first SSO login creates and links a new local account. Existing configuration is preserved; use sso:link-user only for verified existing accounts. Run sso:doctor.');

        return self::SUCCESS;
    }
}
