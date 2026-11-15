<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LaravelSso\Client\IdentityLinks;

Artisan::command('example:grant-report {subject}', function (): void {
    $user = app(IdentityLinks::class)->find(config('sso-client.issuer'), $this->argument('subject'));
    if (! $user) {
        $this->error('No linked local identity exists.');

        return;
    }
    DB::table('report_permissions')->insertOrIgnore(['user_id' => $user->id]);
    $this->info('Permiso concedido sólo en esta base de datos.');
});
