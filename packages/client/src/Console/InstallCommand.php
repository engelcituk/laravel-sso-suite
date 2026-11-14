<?php

declare(strict_types=1);

namespace LaravelSso\Client\Console;

use Illuminate\Console\Command;
use LaravelSso\Contracts\InstallationFiles;

final class InstallCommand extends Command
{
    protected $signature = 'sso:install-client {--migrate : Run pending migrations} {--force : Allow explicit migrations in production}';

    protected $description = 'Install missing client configuration and the single initial migration without overwriting files';

    public function handle(InstallationFiles $files): int
    {
        try {
            $files->install(dirname(__DIR__, 2), config_path(), database_path('migrations'));
        } catch (\RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $this->info('Configuration and initial migration ready; existing files preserved.');
        if ($this->option('migrate')) {
            return $this->call('migrate', ['--force' => (bool) $this->option('force')]);
        }
        $this->line('Review configuration and identity model, then run migrate and sso:doctor --offline.');

        return self::SUCCESS;
    }
}
