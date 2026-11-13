<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LaravelSso\Examples\LocalUser;

Artisan::command('example:grant-report {subject}', function (): void {
    $user = LocalUser::where('sso_issuer', config('sso-client.issuer'))->where('sso_subject', $this->argument('subject'))->firstOrFail();
    DB::table('report_permissions')->insertOrIgnore(['user_id' => $user->id]);
    $this->info('Permiso concedido sólo en esta base de datos.');
});
