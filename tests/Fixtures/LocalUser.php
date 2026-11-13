<?php

namespace LaravelSso\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;
use LaravelSso\Client\SsoOnly;

final class LocalUser extends User
{
    use SsoOnly;

    protected $table = 'users';

    protected $guarded = [];
}
