<?php

declare(strict_types=1);

// Three disposable Laravel hosts, no examples DBs, starter kits or frontend dependencies.
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use LaravelSso\Contracts\InstallationConfiguration;

require dirname(__DIR__).'/vendor/autoload.php';
$root = dirname(__DIR__);
$run = $root.'/.runtime/agnostic-auth/live-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
mkdir($run, 0700, true);
$hosts = ['op' => 'http://127.0.0.1:9300', 'fresh' => 'http://127.0.0.2:9301', 'existing' => 'http://127.0.0.3:9302'];
foreach ($hosts as $origin) {
    $socket = @stream_socket_client('tcp://'.parse_url($origin, PHP_URL_HOST).':'.parse_url($origin, PHP_URL_PORT), $errno, $error, 0.2);
    if (is_resource($socket)) {
        fclose($socket);
        throw new RuntimeException('A requested laboratory port is already in use.');
    }
}
$processes = [];
$report = ['started_at_utc' => gmdate(DATE_ATOM), 'php' => PHP_VERSION, 'checks' => [], 'passed' => false,
    'scope' => 'Disposable standard Laravel hosts, HTTP loopback development only; Laravel config/route caches enabled, PHP OPcache disabled', 'frontend_dependencies' => [],
    'existing_users_changed_by_package' => false, 'new_greenfield_accounts' => 0];
$passwords = ['op' => bin2hex(random_bytes(20)), 'fresh' => bin2hex(random_bytes(20)), 'existing' => bin2hex(random_bytes(20))];
$assert = static function (bool $condition, string $check) use (&$report): void {
    if (! $condition) {
        throw new RuntimeException($check);
    }
    $report['checks'][] = $check;
};
function fixtureArtisan(string $base, array $arguments): string
{
    $process = proc_open([PHP_BINARY, $base.'/artisan', ...$arguments], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $base);
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start fixture Artisan.');
    }
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    file_put_contents($base.'/commands-private.log', $arguments[0].' exit='.$exit."\n".$output.$errors, FILE_APPEND);
    if ($exit !== 0) {
        throw new RuntimeException('Fixture command failed: '.$arguments[0].'; inspect private fixture logs.');
    }

    return $output;
}
function fixtureSnapshot(string $base): array
{
    $pdo = new PDO('sqlite:'.$base.'/database/database.sqlite');
    $result = ['users' => $pdo->query('select * from users order by id')->fetchAll(PDO::FETCH_ASSOC),
        'schema' => $pdo->query('pragma table_info(users)')->fetchAll(PDO::FETCH_ASSOC),
        'acl' => $pdo->query('select * from report_permissions order by user_id')->fetchAll(PDO::FETCH_ASSOC)];
    $pdo = null;

    return $result;
}
$before = [];
$clientIds = [];
try {
    foreach ($hosts as $role => $origin) {
        $base = $run.'/'.$role;
        foreach (['bootstrap/cache', 'config', 'database/migrations', 'public', 'routes', 'resources/views', 'storage/framework/sessions', 'storage/framework/views', 'storage/framework/cache/data', 'storage/logs', 'app'] as $directory) {
            mkdir($base.'/'.$directory, 0755, true);
        }
        file_put_contents($base.'/composer.json', json_encode(['name' => 'fixture/'.$role, 'require' => ['laravel/framework' => '^13.0'],
            'autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_THROW_ON_ERROR));
        file_put_contents($base.'/app/User.php', '<?php class AgnosticFixtureUser extends \\Illuminate\\Foundation\\Auth\\User { protected $table = "users"; protected $fillable = ["name", "email", "password"]; protected $hidden = ["password", "remember_token"]; protected function casts(): array { return ["password" => "hashed", "email_verified_at" => "datetime"]; } }');
        $bootstrap = <<<'PHP'
<?php
require_once ROOT.'/vendor/autoload.php';
require_once dirname(__DIR__).'/app/User.php';
$app = \Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: dirname(__DIR__).'/routes/web.php')
    ->withProviders([\Laravel\Passport\PassportServiceProvider::class, \LaravelSso\Server\SsoServerServiceProvider::class, \LaravelSso\Client\SsoClientServiceProvider::class])
    ->withMiddleware(function (\Illuminate\Foundation\Configuration\Middleware $middleware): void {})
    ->withExceptions(function (\Illuminate\Foundation\Configuration\Exceptions $exceptions): void {})
    ->create();
return $app;
PHP;
        file_put_contents($base.'/bootstrap/app.php', str_replace('ROOT', var_export($root, true), $bootstrap));
        file_put_contents($base.'/config/auth.php', '<?php return ["defaults" => ["guard" => "web", "passwords" => "users"], "guards" => ["web" => ["driver" => "session", "provider" => "users"]], "providers" => ["users" => ["driver" => "eloquent", "model" => AgnosticFixtureUser::class]], "passwords" => ["users" => ["provider" => "users", "table" => "password_reset_tokens", "expire" => 60, "throttle" => 60]]];');
        file_put_contents($base.'/artisan', '<?php $app = require __DIR__."/bootstrap/app.php"; exit($app->handleCommand(new \\Symfony\\Component\\Console\\Input\\ArgvInput));');
        file_put_contents($base.'/public/index.php', '<?php $app = require dirname(__DIR__)."/bootstrap/app.php"; $app->handleRequest(\\Illuminate\\Http\\Request::capture());');
        $routes = <<<'PHP'
<?php
use Illuminate\Support\Facades\Route;
Route::get('/', fn () => ['host' => basename(app()->basePath())]);
Route::get('/public', fn () => ['csrf_token' => csrf_token(), 'sso_enabled' => config('sso-client.enabled'), 'auth_mode' => session('sso.auth_mode'), 'has_identity' => session()->has('sso.identity')]);
Route::get('/health', fn () => ['config_cached' => app()->configurationIsCached(), 'routes_cached' => app()->routesAreCached()]);
Route::middleware('auth')->get('/account', fn () => ['id' => auth()->id(), 'allowed' => \Illuminate\Support\Facades\DB::table('report_permissions')->where('user_id', auth()->id())->exists()]);
PHP;
        if ($role === 'existing') {
            $routes .= <<<'PHP'

Route::get('/login', fn () => ['native' => true, 'csrf_token' => csrf_token()])->name('login');
Route::post('/login', function (\Illuminate\Http\Request $request) {
    abort_unless(\Illuminate\Support\Facades\Auth::attempt($request->only('email', 'password'), false), 422);
    $request->session()->regenerate();
    return ['native' => true];
})->name('login.store');
PHP;
        }
        file_put_contents($base.'/routes/web.php', $routes);
        file_put_contents($base.'/database/migrations/2099_01_01_000000_host_probe.php', '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { throw new \\RuntimeException("Installer ran a host migration"); } };');
        $env = "APP_NAME=AgnosticFixture\nAPP_ENV=local\nAPP_DEBUG=false\nAPP_URL=$origin\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\nDB_CONNECTION=sqlite\nDB_DATABASE=".str_replace('\\', '/', $base)."/database/database.sqlite\nSESSION_DRIVER=file\nSESSION_COOKIE=agnostic_{$role}_session\nSESSION_SECURE_COOKIE=false\nCACHE_STORE=file\nMAIL_MAILER=log\nMAIL_FROM_ADDRESS=no-reply@example.test\nMAIL_FROM_NAME=AgnosticFixture\nBCRYPT_ROUNDS=4\nSSO_SERVER_ENABLED=false\nSSO_CLIENT_ENABLED=false\n";
        file_put_contents($base.'/.env', $env);
        chmod($base.'/.env', 0600);
        $pdo = new PDO('sqlite:'.$base.'/database/database.sqlite');
        $pdo->exec('create table users (id integer primary key autoincrement, name varchar(255) not null, email varchar(255) not null unique, password varchar(255) not null, remember_token text, email_verified_at text, created_at text, updated_at text)');
        $pdo->exec('create table report_permissions (user_id integer primary key)');
        $pdo->exec('create table password_reset_tokens (email varchar(255) primary key, token varchar(255) not null, created_at text)');
        if ($role !== 'fresh') {
            $statement = $pdo->prepare('insert into users (name,email,password,remember_token) values (?,?,?,?)');
            $statement->execute(['Existing synthetic account', 'fixture@example.test', password_hash($passwords[$role], PASSWORD_BCRYPT, ['cost' => 4]), str_repeat('r', 60)]);
        }
        if ($role === 'existing') {
            $pdo->exec('insert into report_permissions values (1)');
        }
        $statement = null;
        $pdo = null;
        $before[$role] = fixtureSnapshot($base);
        if ($role === 'op') {
            fixtureArtisan($base, ['sso:install-server', '--configure', '--issuer='.$origin, '--allow-http', '--migrate', '--keys', '--no-interaction']);
        } else {
            fixtureArtisan($base, ['sso:install-client', '--migrate', '--no-interaction']);
        }
    }
    foreach (['fresh', 'existing'] as $role) {
        $registered = fixtureArtisan($run.'/op', ['sso:client', $role, $hosts[$role].'/sso/callback', '--first-party',
            '--backchannel='.$hosts[$role].'/sso/backchannel-logout', '--trust-backchannel', '--no-interaction']);
        preg_match('/SSO_CLIENT_ID=([^\r\n]+)/', $registered, $id);
        preg_match('/SSO_CLIENT_SECRET=([^\r\n]+)/', $registered, $secret);
        if (! isset($id[1], $secret[1])) {
            throw new RuntimeException('Client registration did not supply credentials.');
        }
        $clientIds[$role] = $id[1];
        fixtureArtisan($run.'/op', ['sso:client-user', $id[1], '1', '--no-interaction']);
        (new InstallationConfiguration)->write($run.'/'.$role.'/.env', ['SSO_CLIENT_SECRET' => $secret[1]]
            + ($role === 'existing' ? ['SSO_CLIENT_PROVISION' => 'restricted'] : []));
        fixtureArtisan($run.'/'.$role, ['sso:install-client', '--configure', '--issuer='.$hosts['op'], '--callback='.$hosts[$role].'/sso/callback', '--client-id='.$id[1], '--allow-http', '--migrate', '--no-interaction']);
        if ($role === 'existing') {
            fixtureArtisan($run.'/'.$role, ['sso:link-user', '1', '1', '--no-interaction']);
        }
    }
    foreach (array_keys($hosts) as $role) {
        fixtureArtisan($run.'/'.$role, ['config:cache']);
        fixtureArtisan($run.'/'.$role, ['route:cache']);
        $jobs = json_decode(fixtureArtisan($run.'/'.$role, ['schedule:list', '--json']), true, flags: JSON_THROW_ON_ERROR);
        $assert(count($jobs) === ($role === 'op' ? 2 : 1), $role.'_operational_schedule_discovered_without_console_routes');
    }
    $start = function (string $role) use ($hosts, $run, &$processes): void {
        $base = $run.'/'.$role;
        $process = proc_open([PHP_BINARY, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-S', parse_url($hosts[$role], PHP_URL_HOST).':'.parse_url($hosts[$role], PHP_URL_PORT), '-t', $base.'/public', $base.'/public/index.php'],
            [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $base.'/http-private.log', 'a'], 2 => ['file', $base.'/http-private.log', 'a']], $pipes, $base);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start owned fixture server.');
        }
        $processes[$role] = $process;
    };
    $client = new Client(['cookies' => new CookieJar, 'http_errors' => false, 'allow_redirects' => false, 'timeout' => 10, 'proxy' => '']);
    foreach ($hosts as $role => $origin) {
        $start($role);
        $ready = false;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            try {
                if ($client->get($origin.'/health')->getStatusCode() === 200) {
                    $ready = true;
                    break;
                }
            } catch (Throwable) {
            }
            usleep(100000);
        }
        $assert($ready, $role.'_real_http_ready');
        $health = json_decode((string) $client->get($origin.'/health')->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $assert($health['config_cached'] && $health['routes_cached'], $role.'_config_and_routes_cached');
    }
    $follow = function (string $url) use ($client): array {
        for ($i = 0; $i < 12; $i++) {
            $response = $client->get($url);
            if ($response->getStatusCode() < 300 || $response->getStatusCode() >= 400) {
                return [$url, $response];
            }
            $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->getHeaderLine('Location')));
        }
        throw new RuntimeException('Unexpected redirect loop.');
    };
    $assert($client->get($hosts['op'].'/sso/auth/login')->getStatusCode() === 200, 'fresh_server_has_optional_login_without_host_login_or_active_column');
    [$url, $response] = $follow($hosts['existing'].'/account');
    $assert($url === $hosts['op'].'/sso/auth/login', 'existing_auth_route_redirects_to_central_fallback_automatically');
    $csrf = json_decode((string) $client->get($hosts['op'].'/sso/auth/session')->getBody(), true, flags: JSON_THROW_ON_ERROR)['csrf_token'];
    $rejected = $client->post($hosts['op'].'/sso/auth/login', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['email' => 'fixture@example.test', 'password' => $passwords['op']]]);
    $assert($rejected->getStatusCode() === 419, 'real_csrf_rejects_missing_token');
    $response = $client->post($hosts['op'].'/sso/auth/login', ['headers' => ['Accept' => 'application/json'],
        'form_params' => ['_token' => $csrf, 'email' => 'fixture@example.test', 'password' => $passwords['op']]]);
    $assert($response->getStatusCode() === 200, 'headless_login_accepts_standard_existing_user');
    $login = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    [$url, $response] = $follow($login['redirect_to']);
    $assert(rtrim($url, '/') === $hosts['existing'] && $response->getStatusCode() === 200, 'real_code_pkce_exchange_completes_existing_client');
    $assert(fixtureSnapshot($run.'/fresh')['users'] === [], 'greenfield_client_has_no_users_before_first_login');
    [$url, $response] = $follow($hosts['fresh'].'/account');
    $assert(rtrim($url, '/') === $hosts['fresh'] && $response->getStatusCode() === 200, 'fresh_client_without_native_login_reuses_central_session');
    $created = fixtureSnapshot($run.'/fresh');
    $assert(count($created['users']) === 1 && $created['users'][0]['email'] === 'fixture@example.test', 'first_sso_login_creates_local_account_without_operator_link');
    $assert(! password_verify($passwords['op'], $created['users'][0]['password']) && $created['users'][0]['remember_token'] === null, 'new_local_account_does_not_copy_central_password_or_recaller');
    $pdo = new PDO('sqlite:'.$run.'/fresh/database/database.sqlite');
    $link = $pdo->query('select issuer,subject,user_id from sso_identities')->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($link) === 1 && $link[0]['issuer'] === $hosts['op'] && $link[0]['subject'] === '1'
        && $link[0]['user_id'] === (string) $created['users'][0]['id'], 'first_sso_login_links_validated_issuer_subject');
    $pdo = null;
    [$url, $response] = $follow($hosts['fresh'].'/sso/login');
    $assert($response->getStatusCode() === 200 && fixtureSnapshot($run.'/fresh') === $created, 'second_sso_login_preserves_local_account_profile_and_hash');
    $report['new_greenfield_accounts'] = 1;
    foreach (['fresh', 'existing'] as $role) {
        $account = $client->get($hosts[$role].'/account', ['headers' => ['Accept' => 'application/json']]);
        $data = json_decode((string) $account->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $assert($account->getStatusCode() === 200 && $data['id'] === 1 && $data['allowed'] === ($role === 'existing'), $role.'_local_id_and_independent_acl');
    }
    $changeMode = function (bool $enabled) use ($run, &$processes, $start): void {
        (new InstallationConfiguration)->write($run.'/existing/.env', ['SSO_CLIENT_ENABLED' => $enabled ? 'true' : 'false']);
        fixtureArtisan($run.'/existing', ['config:cache']); // Routes intentionally stay cached from installation.
        proc_terminate($processes['existing']);
        proc_close($processes['existing']);
        $start('existing');
        usleep(300000);
    };
    $changeMode(false);
    $probe = json_decode((string) $client->get($hosts['existing'].'/public')->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $response = $client->get($hosts['existing'].'/account', ['headers' => ['Accept' => 'application/json']]);
    $report['mode_switch_observation'] = ['sso_enabled' => $probe['sso_enabled'], 'auth_mode' => $probe['auth_mode'], 'has_identity' => $probe['has_identity'], 'protected_status' => $response->getStatusCode()];
    $assert($response->getStatusCode() === 401, 'switch_to_local_rejects_old_sso_session_with_cached_routes');
    $native = json_decode((string) $client->get($hosts['existing'].'/login')->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $response = $client->post($hosts['existing'].'/login', ['form_params' => ['_token' => $native['csrf_token'], 'email' => 'fixture@example.test', 'password' => $passwords['existing']]]);
    $assert($response->getStatusCode() === 200, 'host_native_login_still_works_when_disabled');
    $changeMode(true);
    $assert($client->get($hosts['existing'].'/account', ['headers' => ['Accept' => 'application/json']])->getStatusCode() === 401, 'switch_to_sso_rejects_old_native_session');
    $csrf = json_decode((string) $client->get($hosts['existing'].'/public')->getBody(), true, flags: JSON_THROW_ON_ERROR)['csrf_token'];
    $response = $client->post($hosts['existing'].'/login', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['_token' => $csrf, 'email' => 'fixture@example.test', 'password' => $passwords['existing']]]);
    $assert($response->getStatusCode() === 403, 'native_credentials_blocked_by_discovered_route');
    [$url, $response] = $follow($hosts['existing'].'/sso/login');
    $assert($response->getStatusCode() === 200 && rtrim($url, '/') === $hosts['existing'], 'sso_returns_without_second_central_password');
    $csrf = json_decode((string) $client->get($hosts['op'].'/sso/auth/session')->getBody(), true, flags: JSON_THROW_ON_ERROR)['csrf_token'];
    $response = $client->post($hosts['op'].'/sso/auth/logout', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['_token' => $csrf]]);
    $assert($response->getStatusCode() === 200, 'central_json_logout');
    foreach (['fresh', 'existing'] as $role) {
        $assert($client->get($hosts[$role].'/account', ['headers' => ['Accept' => 'application/json']])->getStatusCode() === 401, $role.'_strict_revocation_after_logout');
    }
    fixtureArtisan($run.'/op', ['sso:deliver-logouts']);
    foreach ($hosts as $role => $origin) {
        if ($role === 'fresh') {
            $after = fixtureSnapshot($run.'/'.$role);
            $assert($after === $created && $after['schema'] === $before[$role]['schema'] && $after['acl'] === $before[$role]['acl'], 'greenfield_only_adds_one_user_without_schema_acl_or_later_credential_changes');
        } else {
            $assert(fixtureSnapshot($run.'/'.$role) === $before[$role], $role.'_existing_users_credentials_schema_acl_unchanged');
        }
        $pdo = new PDO('sqlite:'.$run.'/'.$role.'/database/database.sqlite');
        $assert((int) $pdo->query("select count(*) from migrations where migration='2099_01_01_000000_host_probe'")->fetchColumn() === 0, $role.'_host_pending_migration_not_executed');
        if ($role === 'op') {
            $assert((int) $pdo->query('select count(*) from sso_logout_deliveries where delivered_at is null')->fetchColumn() === 0, 'backchannel_outbox_drained');
        }
        $pdo = null;
    }
    // Descending administration: central account + assigned system, then first client login.
    $operatorEmail = 'operator-new@example.test';
    $signupEmail = 'public-new@example.test';
    $passwordSetup = bin2hex(random_bytes(20));
    $passwordReplacement = bin2hex(random_bytes(20));
    $assert($client->get($hosts['op'].'/sso/auth/register')->getStatusCode() === 404, 'public_registration_closed_by_default');
    $invitation = fixtureArtisan($run.'/op', ['sso:user', $operatorEmail, '--name=Nominas operator', '--client='.$clientIds['fresh'], '--send-reset', '--no-interaction']);
    preg_match('/SSO_SUBJECT=([^\r\n]+)/', $invitation, $subject);
    $assert(isset($subject[1]) && $subject[1] === '2', 'operator_creates_new_central_user_and_assigns_system_without_password_arguments');
    $latestReset = function () use ($run, $hosts): string {
        $mail = str_replace(["=\r\n", "=\n"], '', file_get_contents($run.'/op/storage/logs/laravel.log'));
        preg_match_all('~'.preg_quote($hosts['op'], '~').'/sso/auth/reset-password/([a-f0-9]{64})~', $mail, $matches);
        if ($matches[1] === []) {
            throw new RuntimeException('Private fixture mail did not contain a reset URL.');
        }

        return $matches[1][array_key_last($matches[1])];
    };
    $csrfToken = fn () => json_decode((string) $client->get($hosts['op'].'/sso/auth/session')->getBody(), true, flags: JSON_THROW_ON_ERROR)['csrf_token'];
    $setupToken = $latestReset();
    $response = $client->post($hosts['op'].'/sso/auth/reset-password', ['headers' => ['Accept' => 'application/json'], 'form_params' => [
        '_token' => $csrfToken(), 'email' => $operatorEmail, 'token' => $setupToken, 'password' => $passwordSetup, 'password_confirmation' => $passwordSetup]]);
    $assert($response->getStatusCode() === 200, 'one_use_mail_invitation_sets_only_new_central_account_password');
    $response = $client->post($hosts['op'].'/sso/auth/login', ['headers' => ['Accept' => 'application/json'], 'form_params' => [
        '_token' => $csrfToken(), 'email' => $operatorEmail, 'password' => $passwordSetup]]);
    $assert($response->getStatusCode() === 200, 'operator_created_account_authenticates_at_central_server');
    [$url, $response] = $follow($hosts['fresh'].'/sso/login');
    $account = json_decode((string) $client->get($hosts['fresh'].'/account', ['headers' => ['Accept' => 'application/json']])->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $assert($response->getStatusCode() === 200 && ($account['id'] ?? null) === 2, 'assigned_new_central_user_creates_client_account_on_first_sso_login');
    $secondLocal = fixtureSnapshot($run.'/fresh')['users'][1];
    $known = $client->post($hosts['op'].'/sso/auth/forgot-password', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['_token' => $csrfToken(), 'email' => $operatorEmail]]);
    $unknown = $client->post($hosts['op'].'/sso/auth/forgot-password', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['_token' => $csrfToken(), 'email' => 'unknown@example.test']]);
    $assert($known->getStatusCode() === 202 && $unknown->getStatusCode() === 202 && (string) $known->getBody() === (string) $unknown->getBody(), 'recovery_response_does_not_disclose_account_existence');
    $recoveryToken = $latestReset();
    $resetBody = ['email' => $operatorEmail, 'token' => $recoveryToken, 'password' => $passwordReplacement, 'password_confirmation' => $passwordReplacement];
    $response = $client->post($hosts['op'].'/sso/auth/reset-password', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['_token' => $csrfToken()] + $resetBody]);
    $assert($response->getStatusCode() === 200, 'native_broker_recovery_completes_over_real_http');
    $response = $client->post($hosts['op'].'/sso/auth/reset-password', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['_token' => $csrfToken()] + $resetBody]);
    $assert($response->getStatusCode() === 422, 'recovery_token_cannot_be_replayed');
    $assert($client->get($hosts['fresh'].'/account', ['headers' => ['Accept' => 'application/json']])->getStatusCode() === 401, 'central_recovery_revokes_existing_client_access');
    $assert(fixtureSnapshot($run.'/fresh')['users'][1] === $secondLocal, 'central_recovery_never_changes_client_password_or_profile');
    $response = $client->post($hosts['op'].'/sso/auth/login', ['headers' => ['Accept' => 'application/json'], 'form_params' => [
        '_token' => $csrfToken(), 'email' => $operatorEmail, 'password' => $passwordSetup]]);
    $assert($response->getStatusCode() === 422, 'old_central_password_rejected_after_recovery');
    $response = $client->post($hosts['op'].'/sso/auth/login', ['headers' => ['Accept' => 'application/json'], 'form_params' => [
        '_token' => $csrfToken(), 'email' => $operatorEmail, 'password' => $passwordReplacement]]);
    $assert($response->getStatusCode() === 200, 'new_central_password_authenticates_after_recovery');
    (new InstallationConfiguration)->write($run.'/op/.env', ['SSO_SERVER_REGISTRATION' => 'open']);
    fixtureArtisan($run.'/op', ['config:cache']);
    proc_terminate($processes['op']);
    proc_close($processes['op']);
    $start('op');
    usleep(300000);
    $response = $client->post($hosts['op'].'/sso/auth/register', ['headers' => ['Accept' => 'application/json'], 'form_params' => [
        '_token' => $csrfToken(), 'name' => 'Optional registration', 'email' => $signupEmail, 'password' => $passwordSetup,
        'password_confirmation' => $passwordSetup, 'client_id' => $clientIds['fresh'], 'is_admin' => true]]);
    $assert($response->getStatusCode() === 201, 'optional_stack_independent_registration_creates_new_account');
    $pdo = new PDO('sqlite:'.$run.'/op/database/database.sqlite');
    $assert((int) $pdo->query("select count(*) from sso_client_users where subject='3'")->fetchColumn() === 0, 'public_registration_cannot_self_assign_an_application');
    $pdo = null;
    $assert(fixtureSnapshot($run.'/op')['users'][0] === $before['op']['users'][0], 'central_registration_and_recovery_preserve_preexisting_account');
    $assert(fixtureSnapshot($run.'/fresh')['users'][0] === $created['users'][0]
        && fixtureSnapshot($run.'/existing') === $before['existing'], 'descending_administration_preserves_all_prior_client_accounts_and_acl');
    $report['central_accounts'] = ['operator_created' => 1, 'public_created' => 1, 'mail_transport' => 'private local log, no external mail',
        'password_reset_target' => 'new synthetic operator account only', 'existing_accounts_changed' => false];
    $report['passed'] = true;
} catch (Throwable $error) {
    $report['error_type'] = $error::class;
    $report['error_check'] = $error instanceof RuntimeException ? $error->getMessage() : 'Inspect private fixture logs';
} finally {
    foreach ($processes as $process) {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }
    $report['finished_at_utc'] = gmdate(DATE_ATOM);
    $report['owned_servers_stopped'] = true;
    file_put_contents($run.'/result.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    echo ($report['passed'] ? 'PASS' : 'FAIL').': '.count($report['checks']).' checks; evidence '.str_replace($root.'/', '', $run)."/result.json\n";
    if (! $report['passed']) {
        echo $report['error_type'].': '.$report['error_check']."\n";
    }
}
exit($report['passed'] ? 0 : 1);
