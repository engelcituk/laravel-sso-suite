<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Reset only disposable fixture state between independent official modules.
$root = dirname(__DIR__);
$app = require $root.'/.runtime/conformance/op/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$config = json_decode(file_get_contents($root.'/.runtime/conformance/config.json'), true, 512, JSON_THROW_ON_ERROR);
Cache::flush();
DB::table('sso_logout_deliveries')->delete();
foreach (['client', 'client2'] as $key) {
    DB::table('sso_clients')->where('client_id', $config[$key]['client_id'])->update([
        'backchannel_logout_uri' => ($argv[1] ?? '') === 'backchannel' ? 'https://localhost.emobix.co.uk:8443/test/a/laravel-sso-local/backchannel_logout' : null,
    ]);
}
