<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaravelSso\Client\IdentityLinks;
use LaravelSso\Examples\LocalUser;

require dirname(__DIR__).'/vendor/autoload.php';
if (getenv('SSO_DEPLOYMENT_LAB') !== '1' || dirname(__DIR__) !== '/app') {
    throw new RuntimeException('Use only the isolated deployment laboratory.');
}
[$mode, $profile, $role] = array_pad(array_slice($argv, 1), 3, '');
if (! in_array($mode, ['seed', 'capture', 'verify', 'enable', 'disable'], true)
    || ! in_array($profile, ['fpm', 'apache'], true) || ! in_array($role, ['one', 'two'], true)) {
    throw new RuntimeException('Unknown bounded fixture operation.');
}
$base = '/app/.runtime/deployment-lab/'.$profile.'/'.$role;
if (in_array($mode, ['enable', 'disable'], true)) {
    $env = file_get_contents($base.'/.env');
    $env = preg_replace('/^SSO_CLIENT_ENABLED=.*$/m', 'SSO_CLIENT_ENABLED='.($mode === 'enable' ? 'true' : 'false'), $env, 1, $count);
    if ($count !== 1) {
        throw new RuntimeException('Expected exactly one fixture mode flag.');
    }
    file_put_contents($base.'/.env', $env);
    foreach (['config:cache', 'route:cache'] as $command) {
        $process = proc_open([PHP_BINARY, $base.'/artisan', $command], [STDIN, STDOUT, STDERR], $pipes, $base);
        if (! is_resource($process) || proc_close($process) !== 0) {
            throw new RuntimeException('Fixture cache rebuild failed.');
        }
    }
    exit;
}
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, 'Mode validation failed: '.$error::class."\n");
    exit(1);
});
$manifestFile = '/app/.runtime/deployment-lab/fixture.json';
$manifest = json_decode(file_get_contents($manifestFile), true, flags: JSON_THROW_ON_ERROR);
if ($mode === 'seed') {
    // New synthetic accounts only, before the preservation baseline. Never update an account.
    foreach ([12, 13, 14] as $index) {
        $fixture = $manifest['profiles'][$profile]['users'][$index];
        $user = LocalUser::firstOrCreate(['email' => $fixture['email']], [
            'name' => 'Synthetic mode-switch account', 'password' => bcrypt($fixture['password']),
            'remember_token' => bin2hex(random_bytes(30)),
        ]);
        app(IdentityLinks::class)->link($user, $manifest['profiles'][$profile]['op'], $fixture['subject']);
        if ($role === 'one') {
            DB::table('report_permissions')->insertOrIgnore(['user_id' => $user->getKey()]);
        }
        $manifest['profiles'][$profile]['users'][$index]['local_ids'][$role] = (string) $user->getKey();
    }
    file_put_contents($manifestFile, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    echo 'Three explicitly linked synthetic accounts prepared for '.$profile.'/'.$role.".\n";
    exit;
}
$snapshot = [
    'users_schema_sha256' => hash('sha256', json_encode(Schema::getColumns('users'), JSON_THROW_ON_ERROR)),
    'users_rows_sha256' => hash('sha256', json_encode(DB::table('users')->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR)),
    'permissions_sha256' => hash('sha256', json_encode(DB::table('report_permissions')->orderBy('user_id')->get()->all(), JSON_THROW_ON_ERROR)),
    'user_count' => DB::table('users')->count(),
];
$path = '/results/modes-baseline-'.$profile.'-'.$role.'.json';
if ($mode === 'capture') {
    file_put_contents($path, json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
} else {
    $before = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if ($before !== $snapshot) {
        throw new RuntimeException('Host users/schema/credentials/ACL changed during mode validation.');
    }
    $result = ['checked_at_utc' => gmdate(DATE_ATOM), 'profile' => $profile, 'role' => $role,
        'passed' => true, 'user_count' => $snapshot['user_count'], 'schema_unchanged' => true,
        'all_user_attributes_unchanged' => true, 'passwords_and_remember_tokens_unchanged' => true, 'acl_unchanged' => true];
    file_put_contents('/results/modes-preservation-'.$profile.'-'.$role.'.json', json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    echo 'Users, credentials, schema and ACL unchanged for '.$profile.'/'.$role.".\n";
}
