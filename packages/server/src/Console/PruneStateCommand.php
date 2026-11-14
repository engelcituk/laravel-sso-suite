<?php

declare(strict_types=1);

namespace LaravelSso\Server\Console;

use Illuminate\Console\Command;
use LaravelSso\Server\PruneState;

final class PruneStateCommand extends Command
{
    protected $signature = 'sso:prune-server {--limit=1000}';

    protected $description = 'Prune expired SSO-owned state while preserving pending logout deliveries and recent hints';

    public function handle(PruneState $state): int
    {
        $this->line(json_encode($state->prune((int) $this->option('limit')), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
