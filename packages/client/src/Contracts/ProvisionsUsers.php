<?php

declare(strict_types=1);

namespace LaravelSso\Client\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use LaravelSso\Contracts\Identity;

interface ProvisionsUsers
{
    public function provision(Identity $identity): Authenticatable;
}
