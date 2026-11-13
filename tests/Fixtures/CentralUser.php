<?php

namespace LaravelSso\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

final class CentralUser extends User implements OAuthenticatable
{
    use HasApiTokens;

    protected $table = 'central_users';

    protected $guarded = [];

    protected $hidden = ['password'];
}
