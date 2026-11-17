<?php

namespace LaravelSso\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

final class GreenfieldUser extends User
{
    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }
}
