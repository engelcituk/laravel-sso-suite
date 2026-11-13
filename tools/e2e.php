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
    foreach ($bases as $name => $host) {
        $base = $root.'/examples/'.$name;
        $env = file_get_contents($base.'/.env');
        check(str_contains($env, 'APP_ENV=local'), 'E2E may only operate local development examples.');
        preg_match('/^APP_KEY=(.+)$/m', $env, $match);
        $appKeys[] = trim($match[1]);
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
    $browser = new Client(['cookies' => new CookieJar, 'http_errors' => false, 'allow_redirects' => false, 'timeout' => 10, 'proxy' => '']);
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
    [$url, $response] = $follow($url, 'POST', ['_token' => $csrf, 'email' => $email, 'password' => $password]);
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
    $html = (string) $browser->get('http://127.0.0.2:9101/')->getBody();
    [$url, $response] = $follow('http://127.0.0.2:9101/sso/logout', 'POST', ['_token' => field($html, '_token')]);
    check($response->getStatusCode() === 200 && str_starts_with($url, 'http://127.0.0.1:9100/oidc/logout'), 'RP logout confirmation failed.');
    $html = (string) $response->getBody();
    [$url, $response] = $follow('http://127.0.0.1:9100/oidc/logout', 'POST', [
        '_token' => field($html, '_token'), 'transaction' => field($html, 'transaction'),
    ]);
    check($response->getStatusCode() === 200, 'Central logout failed.');
    check($browser->get('http://127.0.0.3:9102/me', ['headers' => ['Accept' => 'application/json']])->getStatusCode() === 401,
        'Second client accepted a globally revoked session.');
    $process = proc_open([PHP_BINARY, $root.'/examples/identity-server/artisan', 'sso:deliver-logouts'], [STDIN, STDOUT, STDERR], $pipes, $root.'/examples/identity-server');
    check(is_resource($process) && proc_close($process) === 0, 'Back-channel delivery command failed.');
    $pending = $identityDb->query('SELECT COUNT(*) FROM sso_logout_deliveries WHERE delivered_at IS NULL')->fetchColumn();
    check((int) $pending === 0, 'Back-channel deliveries remain pending.');
    echo "PASS: one login, two hosts, same identity, independent ACL/databases/APP_KEY, no RP passwords, global logout and back-channel.\n";
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
    $identityDb->prepare('DELETE FROM users WHERE email=?')->execute([$email]);
}
