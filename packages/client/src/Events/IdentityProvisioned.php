<?php

declare(strict_types=1);

namespace LaravelSso\Client\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use LaravelSso\Contracts\Identity;

final readonly class IdentityProvisioned
{
    public function __construct(public Authenticatable $user, public Identity $identity) {}
}
