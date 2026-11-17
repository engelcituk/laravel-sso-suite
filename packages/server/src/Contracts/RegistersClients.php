<?php

declare(strict_types=1);

namespace LaravelSso\Server\Contracts;

use LaravelSso\Server\ClientDefinition;
use LaravelSso\Server\CreatedClient;

interface RegistersClients
{
    /** Read-only preflight. The optional trust flag does not grant or persist outbound trust. */
    public function validate(ClientDefinition $definition, bool $trustBackchannel = false): void;

    /** Creates both OAuth and SSO records atomically; always enforces configured outbound trust. */
    public function create(ClientDefinition $definition): CreatedClient;
}
