<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\ClientRepository;
use LaravelSso\Examples\CentralUser;

// Private, disposable OP fixture. Never changes the example databases or credentials.
require dirname(__DIR__).'/vendor/autoload.php';
$root = dirname(__DIR__);
$runtime = $root.'/.runtime/conformance';
$base = $runtime.'/op';
$mode = $argv[1] ?? 'strict';
if (! in_array($mode, ['strict', 'compatibility'], true)) {
    throw new RuntimeException('Choose strict or compatibility.');
}
foreach (['bootstrap/cache', 'database/migrations', 'storage/framework/sessions', 'storage/framework/views', 'storage/framework/cache/data', 'storage/logs', 'resources/views', 'routes', 'public'] as $path) {
    @mkdir($base.'/'.$path, 0700, true);
}
@mkdir($runtime.'/tls', 0700, true);
foreach ([$root.'/vendor/laravel/passport/database/migrations', $root.'/packages/server/database/migrations'] as $source) {
    foreach (glob($source.'/*.php') as $path) {
        $suffix = substr(basename($path), 18);
        if (glob($base.'/database/migrations/*'.$suffix) === []) {
            copy($path, $base.'/database/migrations/'.basename($path));
        }
    }
}
foreach (glob($root.'/examples/identity-server/database/migrations/*create_users.php') as $path) {
    copy($path, $base.'/database/migrations/'.basename($path));
}
foreach (glob($root.'/examples/identity-server/resources/views/*') as $path) {
    copy($path, $base.'/resources/views/'.basename($path));
}
copy($root.'/examples/identity-server/routes/web.php', $base.'/routes/web.php');
copy($root.'/examples/identity-server/bootstrap/providers.php', $base.'/bootstrap/providers.php');
$bootstrap = <<<'PHP'
<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
$projectRoot = dirname(__DIR__, 4);
require $projectRoot.'/vendor/autoload.php';
require $projectRoot.'/examples/shared/central-model.php';
$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: dirname(__DIR__).'/routes/web.php')
    ->withMiddleware(function (Middleware $middleware): void {
        // The fixture only accepts traffic via its dedicated Docker TLS ingress.
        $middleware->trustProxies(at: '*');
    })->withExceptions(function (\Illuminate\Foundation\Configuration\Exceptions $exceptions): void {})->create();
$app->useConfigPath($projectRoot.'/examples/shared/config');
$app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app): void {
    $app['config']->set('sso-server.backchannel_allowed_urls', ['https://localhost.emobix.co.uk:8443/test/a/laravel-sso-local/backchannel_logout']);
    foreach (\LaravelSso\Server\UserClaims::PROFILE as $claim) {
        if (!in_array($claim, ['name', 'updated_at'], true)) {
            $app['config']->set('sso-server.claim_attributes.'.$claim, 'oidc_profile.'.$claim);
        }
    }
});
$app->booted(function (): void {
    \Illuminate\Support\Facades\Http::globalOptions([
        'verify' => dirname(__DIR__, 2).'/tls/ca.pem', 'proxy' => '',
        'curl' => getenv('OIDF_CONTAINERIZED') ? [] : [CURLOPT_RESOLVE => ['localhost.emobix.co.uk:8443:127.0.0.1']],
    ]);
});
return $app;
PHP;
file_put_contents($base.'/bootstrap/app.php', $bootstrap);
file_put_contents($base.'/public/index.php', "<?php\n\$app = require dirname(__DIR__).'/bootstrap/app.php';\n\$app->handleRequest(\\Illuminate\\Http\\Request::capture());\n");
copy($root.'/examples/identity-server/artisan', $base.'/artisan');
if (! is_file($base.'/.env')) {
    file_put_contents($base.'/.env', 'APP_NAME="OIDF private fixture"'."\nAPP_ENV=testing\nAPP_DEBUG=false\nAPP_URL=https://identity.sso.test:9443\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\nSESSION_COOKIE=oidf_op_session\nSESSION_SECURE_COOKIE=true\nSSO_SERVER_ENABLED=true\nSSO_CLIENT_ENABLED=false\nSSO_ISSUER=https://identity.sso.test:9443\n");
}
if (! is_file($base.'/database/database.sqlite')) {
    touch($base.'/database/database.sqlite');
}
function command(string $base, array $arguments): void
{
    $process = proc_open([PHP_BINARY, $base.'/artisan', ...$arguments], [STDIN, STDOUT, STDERR], $pipes, $base);
    if (! is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Fixture command failed: '.implode(' ', $arguments));
    }
}
command($base, ['migrate', '--force']);
if (! is_file($base.'/storage/oauth-private.key')) {
    command($base, ['passport:keys']);
}
if (! is_file($base.'/storage/sso/private.pem')) {
    command($base, ['sso:keys']);
}
if (PHP_OS_FAMILY !== 'Windows') {
    foreach (array_merge(glob($base.'/storage/oauth-*.key'), glob($base.'/storage/sso/*.pem')) as $keyFile) {
        if (! chmod($keyFile, 0600)) {
            throw new RuntimeException('Cannot restrict laboratory key permissions.');
        }
    }
}

if (! is_file($runtime.'/tls/ca.pem')) {
    $config = $runtime.'/tls/openssl.cnf';
    file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[v3_ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n[server]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:identity.sso.test,DNS:localhost.emobix.co.uk,IP:127.0.0.1\n");
    $options = ['config' => $config, 'private_key_bits' => 3072, 'digest_alg' => 'sha256'];
    $caKey = openssl_pkey_new($options);
    $caCsr = openssl_csr_new(['commonName' => 'Laravel SSO private OIDF laboratory'], $caKey, $options);
    $ca = openssl_csr_sign($caCsr, null, $caKey, 30, $options + ['x509_extensions' => 'v3_ca']);
    openssl_x509_export_to_file($ca, $runtime.'/tls/ca.pem');
    $key = openssl_pkey_new($options);
    $csr = openssl_csr_new(['commonName' => 'identity.sso.test'], $key, $options);
    $cert = openssl_csr_sign($csr, $ca, $caKey, 30, $options + ['x509_extensions' => 'server']);
    openssl_x509_export_to_file($cert, $runtime.'/tls/server.pem');
    openssl_pkey_export_to_file($key, $runtime.'/tls/server.key', null, $options);
}

$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// Explicit synthetic attributes for this test identity. Missing real-user fields
// remain omitted by the package; the OP never invents profile values at runtime.
if (! Schema::hasColumn('users', 'oidc_profile')) {
    Schema::table('users', function (Blueprint $table): void {
        $table->text('oidc_profile')->nullable();
    });
}
if (! is_file($runtime.'/config.json')) {
    $password = bin2hex(random_bytes(24));
    $email = 'oidf-'.bin2hex(random_bytes(8)).'@example.test';
    CentralUser::create(['name' => 'OIDF laboratory user', 'email' => $email, 'password' => bcrypt($password), 'is_active' => true]);
    $clients = [];
    for ($i = 0; $i < 2; $i++) {
        $client = $app->make(ClientRepository::class)->createAuthorizationCodeGrantClient('OIDF '.($i + 1), ['https://localhost.emobix.co.uk:8443/test/a/laravel-sso-local/callback'], true);
        DB::table('sso_clients')->insert(['client_id' => $client->id, 'auto_approve' => false, 'post_logout_redirect_uris' => json_encode(['https://localhost.emobix.co.uk:8443/test/a/laravel-sso-local/post_logout_redirect'])]);
        $clients[] = ['client_id' => $client->id, 'client_secret' => $client->plainSecret, 'scope' => 'openid profile email'];
    }
    $config = ['alias' => 'laravel-sso-local', 'description' => 'Private Laravel SSO S256 evaluation', 'server' => ['discoveryUrl' => 'https://identity.sso.test:9443/.well-known/openid-configuration'], 'client' => $clients[0], 'client2' => $clients[1],
        'browser' => [['match' => 'https://identity.sso.test:9443/oidc/authorize*', 'tasks' => [
            ['task' => 'Central login', 'optional' => true, 'match' => 'https://identity.sso.test:9443/login*', 'commands' => [['text', 'name', 'email', $email], ['text', 'name', 'password', $password], ['click', 'css', 'button']]],
            ['task' => 'Consent', 'optional' => true, 'match' => 'https://identity.sso.test:9443/oidc/*', 'commands' => [['click', 'css', 'button[value="1"]']]],
            ['task' => 'Callback', 'match' => '*/test/*/callback*', 'commands' => [['wait', 'id', 'submission_complete', 10]]],
        ]]], 'options' => ['browsercontrol_css_enable' => false]];
    file_put_contents($runtime.'/config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}
$config = json_decode(file_get_contents($runtime.'/config.json'), true, 512, JSON_THROW_ON_ERROR);
$testEmail = $config['browser'][0]['tasks'][0]['commands'];
$testEmail = array_values(array_filter($testEmail, fn ($command) => $command[0] === 'text' && $command[2] === 'email'))[0][3];
CentralUser::where('email', $testEmail)->firstOrFail()->update(['oidc_profile' => [
    'given_name' => 'OIDF', 'family_name' => 'Laboratory', 'middle_name' => 'Test',
    'nickname' => 'oidf-test', 'preferred_username' => 'oidf-test',
    'profile' => 'https://identity.sso.test:9443/fixtures/profile',
    'picture' => 'https://identity.sso.test:9443/fixtures/avatar.png', 'website' => 'https://example.test/oidf',
    'gender' => 'unspecified', 'birthdate' => '2000-01-01', 'zoneinfo' => 'America/Cancun', 'locale' => 'es-MX',
]]);
foreach (['client', 'client2'] as $key) {
    DB::table('sso_clients')->where('client_id', $config[$key]['client_id'])->update([
        'backchannel_logout_uri' => 'https://localhost.emobix.co.uk:8443/test/a/laravel-sso-local/backchannel_logout',
        'require_pkce' => $mode === 'strict', 'auto_approve' => true,
    ]);
}
$auth = $config['browser'][0];
$config['client_secret_post'] = $config['client'];
$auth['tasks'][0]['commands'] = array_merge([
    ['wait', 'xpath', '//*', 10, 'Identidad central', 'update-image-placeholder-optional'],
], array_values(array_filter($auth['tasks'][0]['commands'], fn ($command) => $command[0] !== 'wait')));
$config['browser'] = [$auth, ['match' => 'https://identity.sso.test:9443/oidc/logout*', 'tasks' => [
    ['task' => 'Confirm global logout', 'match' => 'https://identity.sso.test:9443/oidc/logout*', 'commands' => [['click', 'css', 'button[value="1"]']]],
    ['task' => 'Logout callback', 'match' => '*/test/*/post_logout_redirect*'],
]]];
$config['override']['oidcc-ensure-registered-redirect-uri']['browser'] = [
    ['match' => 'https://identity.sso.test:9443/oidc/authorize*', 'tasks' => [
        ['task' => 'Unregistered redirect rejected locally', 'match' => 'https://identity.sso.test:9443/oidc/authorize*', 'commands' => [['wait', 'xpath', '//*', 10, 'invalid_request', 'update-image-placeholder']]],
    ]],
];
$config['override']['oidcc-ensure-request-object-with-redirect-uri'] = $config['override']['oidcc-ensure-registered-redirect-uri'];
foreach (['bad-post-logout-redirect-uri', 'modified-id-token-hint', 'no-id-token-hint', 'query-added-to-post-logout-redirect-uri', 'bad-id-token-hint'] as $suffix) {
    $config['override']['oidcc-rp-initiated-logout-'.$suffix]['browser'] = [$auth, [
        'match' => 'https://identity.sso.test:9443/oidc/logout*', 'tasks' => [
            ['task' => 'Invalid logout rejected locally', 'match' => 'https://identity.sso.test:9443/oidc/logout*', 'commands' => [['wait', 'xpath', '//*', 10, 'invalid_request', 'update-image-placeholder']]],
        ],
    ]];
}
foreach (['no-params', 'no-post-logout-redirect-uri', 'only-state'] as $suffix) {
    $config['override']['oidcc-rp-initiated-logout-'.$suffix]['browser'] = [$auth, [
        'match' => 'https://identity.sso.test:9443/oidc/logout*', 'tasks' => [
            ['task' => 'Confirm global logout', 'match' => 'https://identity.sso.test:9443/oidc/logout*', 'commands' => [['click', 'css', 'button[value="1"]']]],
            ['task' => 'Observe logout success', 'match' => 'https://identity.sso.test:9443/oidc/logout/confirm', 'commands' => [['wait', 'xpath', '//*', 10, 'Sesión global cerrada', 'update-image-placeholder']]],
        ],
    ]];
}
file_put_contents($runtime.'/config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo "Private fixture prepared; credentials remain in ignored .runtime/conformance/config.json.\n";
