<?php

declare(strict_types=1);

namespace LaravelSso\Server\Console;

use Illuminate\Console\Command;
use LaravelSso\Contracts\InstallationFiles;

final class InstallCommand extends Command
{
    protected $signature = 'sso:install-server {--migrate : Run pending migrations} {--force : Allow explicit migrations in production} {--keys : Create missing signing keys}';

    protected $description = 'Install missing server configuration and the single initial migration without overwriting files';

    public function handle(InstallationFiles $files): int
    {
        try {
            $files->install(dirname(__DIR__, 2), config_path(), database_path('migrations'));
        } catch (\RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $this->info('Configuration and initial migration ready; existing files preserved.');
        if ($this->option('keys')) {
            $private = config('sso-server.private_key');
            $public = config('sso-server.public_keys')[config('sso-server.signing_kid')] ?? null;
            if (! is_string($private) || ! is_string($public) || ! (file_exists($private) && file_exists($public))) {
                if ($this->call('sso:keys') !== self::SUCCESS) {
                    return self::FAILURE;
                }
            }
        }
        if ($this->option('migrate')) {
            return $this->call('migrate', ['--force' => (bool) $this->option('force')]);
        }
        $this->line('Review configuration and identity model, then run migrate and sso:doctor-server.');

        return self::SUCCESS;
    }
}
