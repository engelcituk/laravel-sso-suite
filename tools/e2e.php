<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

require dirname(__DIR__).'/vendor/autoload.php';
$root = dirname(__DIR__);
function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
function field(string $html, string $name): string
{
    check(preg_match('/name="'.preg_quote($name, '/').'"[^>]*value="([^"]+)"/', $html, $match) === 1, 'Missing form field: '.$name);

    return html_entity_decode($match[1], ENT_QUOTES);
}
$processes = [];
$bases = ['identity-server' => '127.0.0.1:9100', 'app-one' => '127.0.0.2:9101', 'app-two' => '127.0.0.3:9102'];
foreach (array_keys($bases) as $name) {
    check(str_contains(file_get_contents($root.'/examples/'.$name.'/.env'), 'APP_ENV=local'),
        'E2E may only operate local development examples.');
}
$identityDb = new PDO('sqlite:'.$root.'/examples/identity-server/database/database.sqlite');
$email = 'e2e-'.bin2hex(random_bytes(6)).'@example.test';
$password = bin2hex(random_bytes(24));
$identityDb->prepare('INSERT INTO users (name,email,password,is_active,created_at,updated_at) VALUES (?,?,?,1,?,?)')
    ->execute(['E2E identity', $email, password_hash($password, PASSWORD_BCRYPT), date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
$subject = $identityDb->lastInsertId();
try {
    if (! is_dir($root.'/.runtime')) {
        mkdir($root.'/.runtime', 0700);
    }
    $appKeys = [];
    $clientIds = [];
    $sessionCookies = [];
    foreach ($bases as $name => $host) {
        $base = $root.'/examples/'.$name;
        $env = file_get_contents($base.'/.env');
        check(str_contains($env, 'APP_ENV=local'), 'E2E may only operate local development examples.');
        preg_match('/^APP_KEY=(.+)$/m', $env, $match);
        $appKeys[] = trim($match[1]);
        preg_match('/^SESSION_COOKIE=(.+)$/m', $env, $sessionMatch);
        $sessionCookies[$name] = trim($sessionMatch[1]);
        if (preg_match('/^SSO_CLIENT_ID=(.+)$/m', $env, $clientMatch)) {
            $clientIds[$name] = trim($clientMatch[1]);
        }
        [$ip, $port] = explode(':', $host);
        $existing = @fsockopen($ip, (int) $port, $errno, $error, 0.2);
        check($existing === false, 'E2E port already in use: '.$host);
        $process = proc_open([PHP_BINARY, '-S', $host, '-t', $base.'/public', $base.'/public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $root.'/.runtime/'.$name.'.log', 'a'], 2 => ['file', $root.'/.runtime/'.$name.'.log', 'a']], $pipes, $base);
        check(is_resource($process), 'Unable to start example server.');
        fclose($pipes[0]);
        $processes[] = $process;
        $ready = false;
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $socket = @fsockopen($ip, (int) $port, $errno, $error, 0.1);
            if ($socket) {
                fclose($socket);
                $ready = true;
                break;
            }
            usleep(100000);
        }
        check($ready, 'Server did not become ready: '.$host);
    }
    check(count(array_unique($appKeys)) === 3, 'Examples must have distinct APP_KEY values.');
    $jar = new CookieJar;
    $browser = new Client(['cookies' => $jar, 'http_errors' => false, 'allow_redirects' => false, 'timeout' => 10, 'proxy' => '']);
    $follow = function (string $url, string $method = 'GET', array $form = []) use ($browser): array {
        $response = $browser->request($method, $url, $form ? ['form_params' => $form] : []);
        for ($i = 0; $response->getStatusCode() >= 300 && $response->getStatusCode() < 400; $i++) {
            check($i < 12, 'Too many redirects.');
            $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->getHeaderLine('Location')));
            $response = $browser->get($url);
        }

        return [$url, $response];
    };
    [$url, $response] = $follow('http://127.0.0.2:9101/sso/login');
    check($url === 'http://127.0.0.1:9100/login' && $response->getStatusCode() === 200, 'First client must reach central login.');
    $csrf = field((string) $response->getBody(), '_token');
    check($browser->post($url, ['form_params' => ['email' => $email, 'password' => $password]])->getStatusCode() === 419,
        'Central login accepted a request without CSRF.');
    [$url, $response] = $follow($url, 'POST', ['_token' => $csrf, 'email' => $email, 'password' => $password, 'remember' => '1']);
    check(rtrim($url, '/') === 'http://127.0.0.2:9101' && $response->getStatusCode() === 200,
        'First OIDC callback failed at '.parse_url($url, PHP_URL_HOST).parse_url($url, PHP_URL_PATH).' (HTTP '.$response->getStatusCode().').');
    $one = json_decode((string) $browser->get('http://127.0.0.2:9101/me')->getBody(), true);
    check(($one['subject'] ?? null) === $subject && $one['can_view_reports'] === false, 'Local identity or default ACL is wrong.');
    check($browser->get('http://127.0.0.2:9101/reports')->getStatusCode() === 403, 'Unprivileged identity must be denied.');
    $oneDb = new PDO('sqlite:'.$root.'/examples/app-one/database/database.sqlite');
    $oneDb->prepare('INSERT INTO report_permissions (user_id) VALUES (?)')->execute([$one['local_id']]);
    check($browser->get('http://127.0.0.2:9101/reports')->getStatusCode() === 200, 'Local grant not applied.');
    [$url, $response] = $follow('http://127.0.0.3:9102/sso/login');
    check(rtrim($url, '/') === 'http://127.0.0.3:9102' && $response->getStatusCode() === 200, 'Second client requested credentials again or SSO failed.');
    $two = json_decode((string) $browser->get('http://127.0.0.3:9102/me')->getBody(), true);
    check(($two['subject'] ?? null) === $one['subject'] && $two['issuer'] === $one['issuer'], 'Global identities differ.');
    check($two['can_view_reports'] === false && $browser->get('http://127.0.0.3:9102/reports')->getStatusCode() === 403, 'ACL leaked between clients.');
    foreach (['app-one', 'app-two'] as $name) {
        $db = new PDO('sqlite:'.$root.'/examples/'.$name.'/database/database.sqlite');
        $columns = $db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1);
        check(! in_array('password', $columns, true) && ! in_array('remember_token', $columns, true), 'RP stores local credentials.');
    }
    $remembered = $jar->getCookieByName('sso_remember');
    check($remembered !== null && $remembered->getHttpOnly() && $remembered->getDomain() === '127.0.0.1', 'Host-only remembered cookie missing.');
    $originalRememberedValue = $remembered->getValue();
    $jar->clear('127.0.0.1', '/', $sessionCookies['identity-server']);
    $devicesPage = $browser->get('http://127.0.0.1:9100/sso/devices');
    check($devicesPage->getStatusCode() === 200, 'Persistent credential did not recover the central session.');
    check($jar->getCookieByName('sso_remember')->getValue() !== $originalRememberedValue, 'Persistent credential was not rotated.');
    $devicesHtml = (string) $devicesPage->getBody();
    check(str_contains($devicesHtml, 'Confirmar contraseña'), 'Remembered authentication skipped password confirmation.');
    $devicesCsrf = field($devicesHtml, '_token');
    check($browser->post('http://127.0.0.1:9100/sso/devices/revoke-others')->getStatusCode() === 419, 'Device mutation accepted missing CSRF.');
    check($browser->post('http://127.0.0.1:9100/sso/devices/revoke-others', ['headers' => ['Accept' => 'application/json'],
        'form_params' => ['_token' => $devicesCsrf]])->getStatusCode() === 403, 'Device mutation accepted missing password confirmation.');
    check($browser->post('http://127.0.0.1:9100/sso/devices/confirm', ['form_params' => [
        '_token' => $devicesCsrf, 'password' => $password]])->getStatusCode() === 302, 'Device password confirmation failed.');

    $otherJar = new CookieJar;
    $otherBrowser = new Client(['cookies' => $otherJar, 'http_errors' => false, 'allow_redirects' => false, 'timeout' => 10, 'proxy' => '']);
    $otherLogin = $otherBrowser->get('http://127.0.0.1:9100/login');
    check($otherBrowser->post('http://127.0.0.1:9100/login', ['form_params' => [
        '_token' => field((string) $otherLogin->getBody(), '_token'), 'email' => $email, 'password' => $password, 'remember' => '1',
    ]])->getStatusCode() === 302, 'Second device login failed.');
    check($otherBrowser->get('http://127.0.0.2:9101/sso/login', ['allow_redirects' => ['max' => 12]])->getStatusCode() === 200,
        'Second device could not access its RP.');
    check($browser->post('http://127.0.0.1:9100/sso/devices/revoke-others', ['form_params' => ['_token' => $devicesCsrf]])->getStatusCode() === 302,
        'Other-device revocation failed.');
    check($otherBrowser->get('http://127.0.0.2:9101/me', ['headers' => ['Accept' => 'application/json']])->getStatusCode() === 401,
        'RP accepted a revoked device.');
    $otherJar->clear('127.0.0.1', '/', $sessionCookies['identity-server']);
    check($otherBrowser->get('http://127.0.0.1:9100/sso/devices', ['headers' => ['Accept' => 'application/json']])->getStatusCode() === 401,
        'Revoked device recovered access with its remembered cookie.');
    check($browser->get('http://127.0.0.3:9102/me')->getStatusCode() === 200, 'Other-device revocation affected the current device.');
    $externalAuthorization = $browser->post('http://127.0.0.1:9100/oidc/authorize', ['form_params' => [
        'response_type' => 'code', 'client_id' => $clientIds['app-one'], 'redirect_uri' => 'http://127.0.0.2:9101/sso/callback',
        'scope' => 'openid', 'code_challenge_method' => 'S256',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 64), true)), '+/', '-_'), '='),
    ]]);
    check($externalAuthorization->getStatusCode() === 302, 'External authorization POST must not require Laravel CSRF.');
    $html = (string) $browser->get('http://127.0.0.2:9101/')->getBody();
    [$url, $response] = $follow('http://127.0.0.2:9101/sso/logout', 'POST', ['_token' => field($html, '_token')]);
    check($response->getStatusCode() === 200 && str_starts_with($url, 'http://127.0.0.1:9100/oidc/logout'), 'RP logout confirmation failed.');
    parse_str(parse_url($url, PHP_URL_QUERY), $logoutInput);
    $response = $browser->post('http://127.0.0.1:9100/oidc/logout', ['form_params' => $logoutInput]);
    check($response->getStatusCode() === 200, 'External logout POST must reach confirmation without CSRF.');
    $html = (string) $response->getBody();
    check($browser->post('http://127.0.0.1:9100/oidc/logout/confirm', ['form_params' => [
        'transaction' => field($html, 'transaction'),
    ]])->getStatusCode() === 419, 'Logout confirmation accepted a request without CSRF.');
    [$url, $response] = $follow('http://127.0.0.1:9100/oidc/logout/confirm', 'POST', [
        '_token' => field($html, '_token'), 'transaction' => field($html, 'transaction'),
    ]);
    check($response->getStatusCode() === 200, 'Central logout failed.');
    check($browser->get('http://127.0.0.3:9102/me', ['headers' => ['Accept' => 'application/json']])->getStatusCode() === 401,
        'Second client accepted a globally revoked session.');
    $process = proc_open([PHP_BINARY, $root.'/examples/identity-server/artisan', 'sso:deliver-logouts'], [STDIN, STDOUT, STDERR], $pipes, $root.'/examples/identity-server');
    check(is_resource($process) && proc_close($process) === 0, 'Back-channel delivery command failed.');
    $pending = $identityDb->query('SELECT COUNT(*) FROM sso_logout_deliveries WHERE delivered_at IS NULL')->fetchColumn();
    check((int) $pending === 0, 'Back-channel deliveries remain pending.');
    echo "PASS: SSO/ACL isolation, remembered-session recovery and rotation, CSRF/password confirmation, per-device revocation, global logout and back-channel.\n";
} finally {
    foreach ($processes as $process) {
        proc_terminate($process);
        proc_close($process);
    }
    // Remove only the unique identity and dependent example rows created by this run.
    foreach (['app-one', 'app-two'] as $name) {
        $db = new PDO('sqlite:'.$root.'/examples/'.$name.'/database/database.sqlite');
        $stmt = $db->prepare('SELECT id FROM users WHERE sso_subject=? AND sso_issuer=?');
        $stmt->execute([$subject, 'http://127.0.0.1:9100']);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $db->prepare('DELETE FROM report_permissions WHERE user_id=?')->execute([$id]);
            $db->prepare('DELETE FROM sso_local_sessions WHERE subject=?')->execute([$subject]);
            $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
        }
    }
    $sids = $identityDb->prepare('SELECT sid FROM sso_sessions WHERE subject=?');
    $sids->execute([$subject]);
    foreach ($sids->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        foreach (['sso_logout_deliveries', 'sso_token_sessions', 'sso_auth_contexts'] as $table) {
            $identityDb->prepare('DELETE FROM '.$table.' WHERE sid=?')->execute([$sid]);
        }
    }
    $devices = $identityDb->prepare('SELECT id FROM sso_devices WHERE subject=?');
    $devices->execute([$subject]);
    foreach ($devices->fetchAll(PDO::FETCH_COLUMN) as $device) {
        $identityDb->prepare('DELETE FROM sso_remember_tokens WHERE device_id=?')->execute([$device]);
    }
    foreach (['sso_devices', 'sso_sessions'] as $table) {
        $identityDb->prepare('DELETE FROM '.$table.' WHERE subject=?')->execute([$subject]);
    }
    foreach (['oauth_auth_codes', 'oauth_access_tokens'] as $table) {
        $identityDb->prepare('DELETE FROM '.$table.' WHERE user_id=?')->execute([$subject]);
    }
    $identityDb->prepare('DELETE FROM users WHERE email=?')->execute([$email]);
}
