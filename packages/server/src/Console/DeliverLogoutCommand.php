<?php

declare(strict_types=1);

namespace LaravelSso\Server\Console;

use Illuminate\Console\Command;
use LaravelSso\Server\LogoutDeliveries;

final class DeliverLogoutCommand extends Command
{
    protected $signature = 'sso:deliver-logouts {--limit=100}';

    protected $description = 'Deliver due durable back-channel logout notifications with bounded retries';

    public function handle(LogoutDeliveries $deliveries): int
    {
        $count = 0;
        $limit = max(1, min(1000, (int) $this->option('limit')));
        while ($count < $limit && $deliveries->deliverOne()) {
            $count++;
        }
        $this->info('Processed '.$count.' due deliveries; inspect sso_logout_deliveries for pending retries.');

        return self::SUCCESS;
    }
}
