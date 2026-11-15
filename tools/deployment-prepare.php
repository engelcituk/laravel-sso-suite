<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$runtime = $root.'/.runtime/deployment-lab';
$mode = $argv[1] ?? '';
foreach (['tls', 'control', 'results'] as $folder) {
    if (! is_dir($runtime.'/'.$folder)) {
        mkdir($runtime.'/'.$folder, 0700, true);
    }
}
$profiles = [
    'fpm' => ['driver' => 'mysql', 'op' => 'https://identity.idp.test', 'one' => 'https://one.rp-one.test', 'two' => 'https://two.rp-two.test'],
    'apache' => ['driver' => 'pgsql', 'op' => 'https://identity.idp-alt.test', 'one' => 'https://one.rp-one-alt.test', 'two' => 'https://two.rp-two-alt.test'],
];
if ($mode === 'tls') {
    $sameSerial = is_file($runtime.'/tls/ca.pem') && is_file($runtime.'/tls/server.pem')
        && openssl_x509_parse(file_get_contents($runtime.'/tls/ca.pem'))['serialNumberHex'] === openssl_x509_parse(file_get_contents($runtime.'/tls/server.pem'))['serialNumberHex'];
    if (! is_file($runtime.'/tls/ca.pem') || $sameSerial) {
        $domains = [];
        foreach ($profiles as $profile) {
            foreach (['op', 'one', 'two'] as $app) {
                $domains[] = 'DNS:'.parse_url($profile[$app], PHP_URL_HOST);
            }
        }
        $file = $runtime.'/tls/openssl.cnf';
        file_put_contents($file, "[req]\ndistinguished_name=dn\n[dn]\n[v3_ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n[server]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=".implode(',', $domains)."\n");
        $options = ['config' => $file, 'private_key_bits' => 3072, 'digest_alg' => 'sha256'];
        $caKey = openssl_pkey_new($options);
        $ca = openssl_csr_sign(openssl_csr_new(['commonName' => 'Private SSO deployment laboratory'], $caKey, $options), null, $caKey, 30, $options + ['x509_extensions' => 'v3_ca'], random_int(1, 1073741823));
        openssl_x509_export_to_file($ca, $runtime.'/tls/ca.pem');
        $key = openssl_pkey_new($options);
        $csr = openssl_csr_new(['commonName' => 'identity.idp.test'], $key, $options);
        $cert = openssl_csr_sign($csr, $ca, $caKey, 30, $options + ['x509_extensions' => 'server'], random_int(1073741824, PHP_INT_MAX));
        openssl_x509_export_to_file($cert, $runtime.'/tls/server.pem');
        openssl_pkey_export_to_file($key, $runtime.'/tls/server.key', null, $options);
    }
    echo "Private laboratory TLS prepared; no host trust store changed.\n";
    exit;
}
if ($mode !== 'applications' || getenv('SSO_DEPLOYMENT_LAB') !== '1' || PHP_OS_FAMILY === 'Windows' || $root !== '/app') {
    throw new RuntimeException('Application preparation requires the isolated deployment volume.');
}
foreach (glob('/tls/*') as $file) {
    copy($file, $runtime.'/tls/'.basename($file));
}
function laboratoryArtisan(string $base, array $arguments): void
{
    $process = proc_open([PHP_BINARY, $base.'/artisan', ...$arguments], [STDIN, STDOUT, STDERR], $pipes, $base);
    if (! is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Laboratory Artisan command failed: '.$arguments[0]);
    }
}
$manifest = is_file($runtime.'/fixture.json') ? json_decode(file_get_contents($runtime.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR) : ['profiles' => []];
foreach ($profiles as $profileName => $profile) {
    foreach (['op', 'one', 'two'] as $role) {
        $base = $runtime.'/'.$profileName.'/'.$role;
        foreach (['bootstrap/cache', 'config', 'database/migrations', 'public', 'routes', 'resources/views', 'storage/logs', 'storage/framework/sessions', 'storage/framework/views', 'storage/framework/cache/data'] as $folder) {
            if (! is_dir($base.'/'.$folder)) {
                mkdir($base.'/'.$folder, 0755, true);
            }
        }
        $example = $role === 'op' ? 'identity-server' : 'app-one';
        copy($root.'/examples/'.$example.'/artisan', $base.'/artisan');
        file_put_contents($base.'/composer.json', json_encode(['name' => 'laboratory/'.$profileName.'-'.$role,
            'autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_THROW_ON_ERROR));
        copy($root.'/examples/'.$example.'/bootstrap/providers.php', $base.'/bootstrap/providers.php');
        foreach (glob($root.'/examples/shared/config/*.php') as $file) {
            file_put_contents($base.'/config/'.basename($file), '<?php return require '.var_export($file, true).';');
        }
        $bootstrap = <<<'PHP'
<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
$root = '/app';
$base = dirname(__DIR__);
require_once $root.'/vendor/autoload.php';
require_once $root.'/examples/shared/'.(basename($base) === 'op' ? 'central-model.php' : 'client-model.php');
require_once $root.'/conformance/deployment/FixtureExceptionHandler.php';
$app = Application::configure(basePath: $base)
    ->withRouting(web: $base.'/routes/web.php')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: ['172.29.231.10']);
        $middleware->redirectGuestsTo('/sso/login');
    })->withExceptions(function (\Illuminate\Foundation\Configuration\Exceptions $exceptions): void {})->create();
$app->singleton(\Illuminate\Contracts\Debug\ExceptionHandler::class, \LaravelSso\Conformance\FixtureExceptionHandler::class);
$app->booted(function (): void {
    \Illuminate\Support\Facades\Http::globalOptions(['verify' => '/app/.runtime/deployment-lab/tls/ca.pem', 'proxy' => '']);
});
return $app;
PHP;
        file_put_contents($base.'/bootstrap/app.php', $bootstrap);
        file_put_contents($base.'/public/index.php', '<?php $app = require dirname(__DIR__).\'/bootstrap/app.php\'; $app->handleRequest(\Illuminate\Http\Request::capture());');
        if ($role === 'op') {
            $routes = str_replace('throttle:5,1,central-login', 'throttle:600,1,lab-login', file_get_contents($root.'/examples/identity-server/routes/web.php'));
            file_put_contents($base.'/routes/web.php', $routes);
            foreach (glob($root.'/examples/identity-server/resources/views/*') as $file) {
                copy($file, $base.'/resources/views/'.basename($file));
            }
        } else {
            file_put_contents($base.'/routes/web.php', '<?php require \'/app/examples/shared/client-routes.php\';');
        }
        file_put_contents($base.'/routes/web.php', "\nrequire '/app/conformance/deployment/fixture-routes.php';\n", FILE_APPEND);
        $sources = [$root.'/packages/'.($role === 'op' ? 'server' : 'client').'/database/migrations'];
        if ($role === 'op') {
            $sources[] = $root.'/vendor/laravel/passport/database/migrations';
        }
        foreach ($sources as $source) {
            foreach (glob($source.'/*.php') as $file) {
                copy($file, $base.'/database/migrations/'.basename($file));
            }
        }
        $hostMigration = glob($root.'/examples/'.$example.'/database/migrations/*create_users.php')[0];
        $migration = <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        (require HOST_MIGRATION)->up();
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary(); $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address',45)->nullable(); $table->text('user_agent')->nullable();
            $table->longText('payload'); $table->integer('last_activity')->index();
        });
    }
    public function down(): void { Schema::dropIfExists('sessions'); (require HOST_MIGRATION)->down(); }
};
PHP;
        file_put_contents($base.'/database/migrations/2026_01_01_000000_create_fixture_users.php', str_replace('HOST_MIGRATION', var_export($hostMigration, true), $migration));
        $driver = $profile['driver'];
        $database = ['default' => $driver, 'connections' => [$driver => [
            'driver' => $driver, 'host' => $driver === 'mysql' ? 'mysql' : 'postgres', 'port' => $driver === 'mysql' ? 3306 : 5432,
            'database' => $profileName.'_'.$role, 'username' => 'sso_lab', 'password' => getenv('LAB_PASSWORD'),
            'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8', 'collation' => $driver === 'mysql' ? 'utf8mb4_bin' : null,
            'prefix' => '', 'strict' => true,
        ]], 'migrations' => ['table' => 'migrations', 'update_date_on_publish' => false]];
        file_put_contents($base.'/config/database.php', '<?php return '.var_export($database, true).';');
        $session = "<?php \$config = require '/app/examples/shared/config/session.php'; \$config['driver'] = ".var_export($profileName === 'fpm' ? 'file' : 'database', true).'; return $config;';
        file_put_contents($base.'/config/session.php', $session);
        file_put_contents($base.'/config/logging.php', "<?php \$config = require '/app/examples/shared/config/logging.php'; \$config['channels']['single']['level']='info'; return \$config;");
        $server = "<?php \$config = require '/app/packages/server/config/sso-server.php'; \$config['protocol_throttle']='6000,1,sso-lab'; \$config['audit_log']=true; \$config['session_idle']=120; \$config['session_max']=300; \$config['remember_ttl']=900; \$config['access_token_ttl']=120; \$config['id_token_ttl']=120; \$config['backchannel_allowed_urls']=".var_export([$profile['one'].'/sso/backchannel-logout', $profile['two'].'/sso/backchannel-logout'], true).'; return $config;';
        file_put_contents($base.'/config/sso-server.php', $server);
        $client = "<?php \$config = require '/app/packages/client/config/sso-client.php'; \$config['user_model']=\\LaravelSso\\Examples\\LocalUser::class; \$config['provision']='automatic'; \$config['audit_log']=true; \$config['local_session_ttl']=120; \$config['allowed_urls']=".var_export(array_map(fn ($path) => $profile['op'].$path, ['/.well-known/openid-configuration', '/oidc/authorize', '/oidc/token', '/oidc/jwks', '/oidc/userinfo', '/oidc/session', '/oidc/logout']), true).'; return $config;';
        file_put_contents($base.'/config/sso-client.php', $client);
        if (! is_file($base.'/.env')) {
            $env = 'APP_NAME="SSO deployment laboratory"'."\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\nAPP_URL=".$profile[$role]."\nSESSION_COOKIE=".$profileName.'_'.$role."_session\nSESSION_SECURE_COOKIE=true\nSSO_SERVER_ENABLED=".($role === 'op' ? 'true' : 'false')."\nSSO_CLIENT_ENABLED=".($role === 'op' ? 'false' : 'true')."\nSSO_ISSUER=".$profile['op']."\nSSO_REDIRECT_URI=".$profile[$role]."/sso/callback\nSSO_SESSION_STATUS_URL=".$profile['op']."/oidc/session\n";
            file_put_contents($base.'/.env', $env);
        }
        if (is_file($base.'/bootstrap/cache/config.php')) {
            unlink($base.'/bootstrap/cache/config.php');
        }
        laboratoryArtisan($base, ['migrate', '--force']);
        if ($role === 'op') {
            if (! is_file($base.'/storage/oauth-private.key')) {
                laboratoryArtisan($base, ['passport:keys']);
            }
            if (! is_file($base.'/storage/sso/private.pem')) {
                laboratoryArtisan($base, ['sso:keys']);
            }
        }
    }
    $manifest['profiles'][$profileName] = ($manifest['profiles'][$profileName] ?? []) + $profile;
}
file_put_contents($runtime.'/fixture.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
// Each seeding process boots exactly one application; Laravel globals never cross DB boundaries.
foreach ($profiles as $profileName => $profile) {
    $process = proc_open([PHP_BINARY, $root.'/tools/deployment-control.php', 'seed', $profileName], [STDIN, STDOUT, STDERR], $pipes, $root);
    if (! is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Fixture seeding failed.');
    }
}
foreach (array_keys($profiles) as $profileName) {
    foreach (['op', 'one', 'two'] as $role) {
        laboratoryArtisan($runtime.'/'.$profileName.'/'.$role, ['optimize']);
    }
}
// Only this fixed disposable fixture tree is owned by the web process.
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
chmod(dirname($runtime), 0755);
chown($runtime, 33);
chgrp($runtime, 33);
chmod($runtime, 0755);
foreach ($iterator as $file) {
    chown($file->getPathname(), 33);
    chgrp($file->getPathname(), 33);
    chmod($file->getPathname(), $file->isDir() ? 0755 : (in_array($file->getExtension(), ['pem', 'key'], true) || $file->getFilename() === '.env' || str_ends_with($file->getPathname(), '/config/database.php') || str_ends_with($file->getPathname(), '/bootstrap/cache/config.php') || $file->getFilename() === 'fixture.json' ? 0600 : 0644));
}
echo "Six isolated production-mode fixture applications optimized; credentials stay in the private volume.\n";
