<?php

declare(strict_types=1);

namespace LaravelSso\Server\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use LaravelSso\Server\ClientChanges;
use LaravelSso\Server\ClientDetails;

/** The host authorizes its operator before calling these application-scoped operations. */
interface ManagesClients
{
    /** @return LengthAwarePaginator<int, ClientDetails> */
    public function listing(string $status = 'active', int $perPage = 20, int $page = 1): LengthAwarePaginator;

    public function find(string $id): ClientDetails;

    public function update(string $id, ClientChanges $changes): ClientDetails;

    /** Irreversible and idempotent; retains registration and assignments for inspection. */
    public function revoke(string $id): ClientDetails;
}
