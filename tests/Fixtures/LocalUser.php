<?php

namespace LaravelSso\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

final class LocalUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}
