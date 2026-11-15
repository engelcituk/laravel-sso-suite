<?php

declare(strict_types=1);
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\TransferStats;

require dirname(__DIR__).'/vendor/autoload.php';
if (getenv('SSO_DEPLOYMENT_LAB') !== '1' || dirname(__DIR__) !== '/app') {
    throw new RuntimeException('Use only the isolated deployment laboratory.');
}
$manifest = json_decode(file_get_contents('/app/.runtime/deployment-lab/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
$http = new Client(['verify' => '/app/.runtime/deployment-lab/tls/ca.pem', 'proxy' => '', 'timeout' => 10,
    'http_errors' => false, 'allow_redirects' => false]);
$report = ['checked_at_utc' => gmdate(DATE_ATOM), 'checker_sha256' => hash_file('sha256', __FILE__), 'https_errors_ignored' => false, 'checks' => []];
$spoofed = ['X-Forwarded-Proto' => 'http', 'X-Forwarded-Host' => 'attacker.invalid', 'X-Forwarded-Port' => '80', 'X-Forwarded-For' => '203.0.113.4'];
foreach ($manifest['profiles'] as $name => $profile) {
    foreach (['op', 'one', 'two'] as $role) {
        $response = $http->get($profile[$role].'/lab/health', ['headers' => $spoofed]);
        $health = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if ($response->getStatusCode() !== 200 || ! $health['secure'] || $health['host'] !== parse_url($profile[$role], PHP_URL_HOST)) {
            throw new RuntimeException('Untrusted forwarding headers influenced the trusted origin.');
        }
        $report['checks'][] = ['profile' => $name, 'role' => $role, 'check' => 'spoofed_forwarded_origin_replaced', 'passed' => true];
        if ($role !== 'op') {
            $response = $http->get($profile[$role].'/sso/login', ['headers' => $spoofed]);
            $location = $response->getHeaderLine('Location');
            parse_str(parse_url($location, PHP_URL_QUERY) ?: '', $query);
            if ($response->getStatusCode() !== 302 || ! str_starts_with($location, $profile['op'].'/oidc/authorize?') || ($query['redirect_uri'] ?? null) !== $profile[$role].'/sso/callback') {
                throw new RuntimeException('Spoofed forwarding headers influenced an OIDC redirect.');
            }
            $report['checks'][] = ['profile' => $name, 'role' => $role, 'check' => 'configured_https_oidc_redirect_preserved', 'passed' => true];
        }
    }
    $rejected = false;
    $curlError = null;
    try {
        $response = $http->get($profile['op'].'/lab/health', ['headers' => ['Host' => 'attacker.invalid'],
            'on_stats' => static function (TransferStats $stats) use (&$curlError): void {
                $curlError = $stats->getHandlerErrorData();
            }]);
        $rejected = $response->getStatusCode() === 444;
    } catch (TransferException $error) {
        $rejected = $curlError === 52; // Nginx 444 deliberately sends no HTTP response.
    }
    if (! $rejected) {
        throw new RuntimeException('Unknown Host was not rejected by the ingress.');
    }
    $report['checks'][] = ['profile' => $name, 'role' => 'ingress', 'check' => 'unknown_host_rejected', 'passed' => true];
}
$report['passed'] = count($report['checks']) === 12;
if (file_put_contents('/results/proxy-check.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === false) {
    throw new RuntimeException('Cannot retain proxy-check evidence.');
}
echo "Twelve trusted-proxy/Host checks passed; no redirect parameters or cookies exported.\n";
