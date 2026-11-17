<?php

declare(strict_types=1);

namespace LaravelSso\Server\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LaravelSso\Server\Contracts\ManagesClients;

final class RevokeClientCommand extends Command
{
    protected $signature = 'sso:client-revoke {client}';

    protected $description = 'Permanently revoke one SSO application and its codes and tokens';

    public function handle(ManagesClients $clients): int
    {
        try {
            $client = $clients->revoke($this->argument('client'));
            $this->line(json_encode($client, JSON_THROW_ON_ERROR));
        } catch (ModelNotFoundException|\RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
