<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use LaravelSso\Examples\CentralUser;

Artisan::command('example:seed', function (ClientRepository $clients): void {
    $password = bin2hex(random_bytes(12));
    $user = CentralUser::firstOrCreate(['email' => 'demo@example.test'], [
        'name' => 'Demo', 'password' => bcrypt($password), 'is_active' => true,
    ]);
    if ($user->wasRecentlyCreated) {
        $this->line('Usuario: demo@example.test');
        $this->line('Contraseña local de desarrollo: '.$password);
    }
    foreach (['app-one' => 'http://127.0.0.2:9101', 'app-two' => 'http://127.0.0.3:9102'] as $name => $url) {
        $client = Passport::client()->newQuery()->where('name', $name)->first();
        $secret = null;
        if (! $client) {
            $client = $clients->createAuthorizationCodeGrantClient($name, [$url.'/sso/callback'], true);
            $secret = $client->plainSecret;
            DB::table('sso_clients')->insert(['client_id' => $client->id, 'auto_approve' => true,
                'backchannel_logout_uri' => $url.'/sso/backchannel-logout', 'post_logout_redirect_uris' => json_encode([$url.'/'])]);
        }
        $path = dirname(base_path()).'/'.$name.'/.env';
        $env = file_get_contents($path);
        $env = preg_replace('/^SSO_CLIENT_ID=.*$/m', 'SSO_CLIENT_ID='.$client->id, $env);
        if ($secret !== null) {
            $env = preg_replace('/^SSO_CLIENT_SECRET=.*$/m', 'SSO_CLIENT_SECRET='.$secret, $env);
        }
        file_put_contents($path, $env);
    }
})->purpose('Create a central demo identity and two independent confidential OIDC clients');
