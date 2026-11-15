<?php

namespace LaravelSso\Examples;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LaravelSso\Contracts\Identity;

final class LocalUser extends User
{
    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];
}

/** Host policy for this demonstration; existing accounts require explicit linking. */
final class CreateLocalUser
{
    public function __invoke(Identity $identity): LocalUser
    {
        return LocalUser::create(array_filter(array_intersect_key($identity->attributes, array_flip(['name', 'email'])), is_string(...))
            + ['password' => Hash::make(Str::random(64))]);
    }
}

final class Report {}

final class ReportPolicy
{
    public function view(LocalUser $user, Report $report): bool
    {
        return DB::table('report_permissions')->where('user_id', $user->id)->exists();
    }
}
