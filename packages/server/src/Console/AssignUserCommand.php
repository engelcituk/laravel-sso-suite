<?php

declare(strict_types=1);

namespace LaravelSso\Server\Console;

use Illuminate\Console\Command;
use LaravelSso\Server\ClientUsers;

final class AssignUserCommand extends Command
{
    protected $signature = 'sso:client-user {client} {user : Central subject ID} {--remove}';

    protected $description = 'Grant or remove a central user application assignment; never synchronize local accounts';

    public function handle(ClientUsers $access): int
    {
        try {
            $access->assign($this->argument('client'), $this->argument('user'), (bool) $this->option('remove'));
        } catch (\RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $this->info($this->option('remove') ? 'Assignment removed and existing application tokens revoked.' : 'Application assignment ready.');

        return self::SUCCESS;
    }
}
