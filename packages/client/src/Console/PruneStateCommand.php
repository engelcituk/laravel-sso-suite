<?php

declare(strict_types=1);

namespace LaravelSso\Client\Console;

use Illuminate\Console\Command;
use LaravelSso\Client\PruneState;

final class PruneStateCommand extends Command
{
    protected $signature = 'sso:prune-client {--limit=1000}';

    protected $description = 'Prune revoked or expired local indexes and receipts beyond the accepted replay window';

    public function handle(PruneState $state): int
    {
        $this->line(json_encode($state->prune((int) $this->option('limit')), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
