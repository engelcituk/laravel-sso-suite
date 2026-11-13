<?php

namespace LaravelSso\Examples;

use Illuminate\Foundation\Auth\User;

final class CentralUser extends User
{
    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['oidc_profile' => 'array'];
    }
}
