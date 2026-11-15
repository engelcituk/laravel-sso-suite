<?php

declare(strict_types=1);

namespace LaravelSso\Client\Console;

use Illuminate\Console\Command;
use LaravelSso\Client\IdentityLinks;

final class LinkUserCommand extends Command
{
    protected $signature = 'sso:link-user {user : Local primary key} {subject : Verified central subject} {--issuer= : Defaults to the configured issuer}';

    protected $description = 'Explicitly link a verified central identity to an existing local user without changing user data';

    public function handle(IdentityLinks $links): int
    {
        $model = config('sso-client.user_model');
        $user = (new $model)->newQuery()->find($this->argument('user'));
        if (! $user) {
            $this->error('Local user not found. No account was created.');

            return self::FAILURE;
        }
        $links->link($user, $this->option('issuer') ?: config('sso-client.issuer'), $this->argument('subject'));
        $this->info('Identity linked; local user data and credentials preserved.');

        return self::SUCCESS;
    }
}
