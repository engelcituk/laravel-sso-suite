<?php

namespace LaravelSso\Examples;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use LaravelSso\Client\SsoOnly;

final class LocalUser extends User
{
    use SsoOnly;

    protected $table = 'users';

    protected $fillable = ['sso_issuer', 'sso_subject', 'name', 'email'];
}

final class Report {}

final class ReportPolicy
{
    public function view(LocalUser $user, Report $report): bool
    {
        return DB::table('report_permissions')->where('user_id', $user->id)->exists();
    }
}
