<?php

declare(strict_types=1);
use GuzzleHttp\Client;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use LaravelSso\Examples\CentralUser;
use LaravelSso\Server\LogoutDeliveries;

require dirname(__DIR__).'/vendor/autoload.php';
if (getenv('SSO_DEPLOYMENT_LAB') !== '1' || dirname(__DIR__) !== '/app') {
    throw new RuntimeException('Use only the isolated deployment laboratory.');
}
$runtime = '/app/.runtime/deployment-lab';
$manifest = json_decode(file_get_contents($runtime.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
$mode = $argv[1] ?? 'inspect';
$profileName = $argv[2] ?? '';
$role = $argv[3] ?? 'op';
function bootFixture(string $profile, string $role): Application
{
    if (! in_array($profile, ['fpm', 'apache'], true) || ! in_array($role, ['op', 'one', 'two'], true)) {
        throw new RuntimeException('Unknown fixture.');
    }
    $app = require '/app/.runtime/deployment-lab/'.$profile.'/'.$role.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    return $app;
}
if ($mode === 'seed') {
    $app = bootFixture($profileName, 'op');
    $profile = &$manifest['profiles'][$profileName];
    $profile['users'] ??= array_map(static fn ($id) => ['email' => 'lab-'.$id.'-'.bin2hex(random_bytes(4)).'@example.test', 'password' => bin2hex(random_bytes(24))], range(0, 15));
    foreach ($profile['users'] as $user) {
        CentralUser::firstOrCreate(['email' => $user['email']], ['name' => 'Synthetic deployment user', 'password' => bcrypt($user['password']), 'is_active' => true]);
    }
    foreach (['one', 'two'] as $clientName) {
        $client = isset($profile['clients'][$clientName]) ? Passport::client()->newQuery()->find($profile['clients'][$clientName]) : null;
        if (! $client) {
            $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Deployment '.$clientName, [$profile[$clientName].'/sso/callback'], true);
            DB::table('sso_clients')->insert(['client_id' => $client->getKey(), 'auto_approve' => true,
                'backchannel_logout_uri' => $profile[$clientName].'/sso/backchannel-logout']);
            $envFile = $runtime.'/'.$profileName.'/'.$clientName.'/.env';
            $env = preg_replace('/^SSO_CLIENT_(ID|SECRET)=.*\R?/m', '', file_get_contents($envFile));
            file_put_contents($envFile, $env.'SSO_CLIENT_ID='.$client->getKey()."\nSSO_CLIENT_SECRET=".$client->plainSecret."\n");
            $profile['clients'][$clientName] = $client->getKey();
        }
    }
    file_put_contents($runtime.'/fixture.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    echo 'Seeded '.$profileName." synthetic identities and clients.\n";
    exit;
}
if ($mode === 'worker') {
    bootFixture($profileName, 'op');
    $deadline = microtime(true) + (int) ($argv[3] ?? 60);
    $processed = 0;
    while (microtime(true) < $deadline) {
        clearstatcache(true, '/control/pause-worker');
        if (! is_file('/control/pause-worker')) {
            for ($i = 0; $i < 100 && app(LogoutDeliveries::class)->deliverOne(); $i++) {
                $processed++;
            }
        }
        usleep(1000000);
    }
    file_put_contents('/results/worker-'.$profileName.'.json', json_encode(['processed' => $processed], JSON_THROW_ON_ERROR));
    exit;
}
if ($mode === 'check') {
    $app = bootFixture($profileName, $role);
    $command = $role === 'op' ? 'sso:doctor-server' : 'sso:doctor';
    $exit = Artisan::call($command, ['--json' => true]);
    $doctor = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $result = ['doctor' => $doctor, 'configuration_cached' => $app->configurationIsCached(), 'routes_cached' => $app->routesAreCached()];
    if ($role === 'op') {
        $db = DB::connection();
        $result['state'] = [
            'pending_deliveries' => $db->table('sso_logout_deliveries')->whereNull('delivered_at')->count(),
            'delivered' => $db->table('sso_logout_deliveries')->whereNotNull('delivered_at')->count(),
            'max_attempts' => (int) ($db->table('sso_logout_deliveries')->max('attempts') ?? 0),
            'sessions' => $db->table('sso_sessions')->count(), 'devices' => $db->table('sso_devices')->count(),
            'remembered_rotations' => $db->table('sso_remember_tokens')->whereNotNull('used_at')->count(),
        ];
    } else {
        $result['state'] = ['local_sessions' => DB::table('sso_local_sessions')->count(),
            'receipts' => DB::table('sso_logout_receipts')->count(),
            'identity_rows' => DB::table('users')->count()];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit($exit);
}
if ($mode !== 'inspect') {
    throw new RuntimeException('Unknown laboratory control mode.');
}
$report = ['checked_at_utc' => gmdate(DATE_ATOM), 'profiles' => [], 'source_sha256' => (require __DIR__.'/deployment-source.php')('/app')];
$http = new Client(['verify' => $runtime.'/tls/ca.pem', 'proxy' => '', 'http_errors' => false, 'timeout' => 15]);
foreach ($manifest['profiles'] as $name => $profile) {
    foreach (['op', 'one', 'two'] as $app) {
        $ready = false;
        for ($i = 0; $i < 30; $i++) {
            try {
                $response = $http->get($profile[$app].'/lab/health');
                if ($response->getStatusCode() === 200) {
                    $ready = true;
                    break;
                }
            } catch (Throwable) {
            }
            usleep(500000);
        }
        if (! $ready) {
            throw new RuntimeException('Fixture not ready: '.$name.'/'.$app);
        }
        $health = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if (! $health['secure'] || ! $health['configuration_cached'] || ! $health['routes_cached'] || ! $health['opcache']) {
            throw new RuntimeException('Unoptimized/insecure deployment fixture.');
        }
        $process = proc_open([PHP_BINARY, __FILE__, 'check', $name, $app], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        fclose($pipes[0]);
        $check = json_decode(stream_get_contents($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
        fclose($pipes[1]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Doctor failed: '.$name.'/'.$app);
        }
        $report['profiles'][$name][$app] = ['health' => $health] + $check;
    }
}
file_put_contents('/results/deployment-inspection.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents('/results/inspection-'.gmdate('Ymd-His').'.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo "Six optimized HTTPS deployments and doctors passed; no secrets exported.\n";
