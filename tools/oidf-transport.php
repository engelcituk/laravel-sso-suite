<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\TransferStats;

require dirname(__DIR__).'/vendor/autoload.php';
if (! getenv('OIDF_CONTAINERIZED')) {
    throw new RuntimeException('Run this check in the private OP container.');
}
$root = dirname(__DIR__);
$timings = [];
$failures = [];
$passed = 0;
$client = new Client(['verify' => $root.'/.runtime/conformance/tls/ca.pem', 'proxy' => '',
    'timeout' => 5, 'connect_timeout' => 3,
    'on_stats' => function (TransferStats $stats) use (&$timings): void {
        $timings[] = $stats->getTransferTime();
    }]);
$requests = function () use ($client): Generator {
    for ($index = 0; $index < 80; $index++) {
        yield fn () => $client->getAsync('https://identity.sso.test:9443/.well-known/openid-configuration');
    }
};
$pool = new Pool($client, $requests(), ['concurrency' => 4,
    'fulfilled' => function ($response) use (&$passed, &$failures): void {
        $metadata = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() === 200 && ($metadata['issuer'] ?? null) === 'https://identity.sso.test:9443') {
            $passed++;
        } else {
            $failures[] = 'Unexpected discovery response.';
        }
    },
    'rejected' => function ($reason) use (&$failures): void {
        $failures[] = get_debug_type($reason);
    },
]);
$pool->promise()->wait();
$report = ['checked_at_utc' => gmdate(DATE_ATOM), 'requests' => 80, 'concurrency' => 4,
    'passed' => $passed, 'failures' => $failures, 'request_timeout_seconds' => 5,
    'max_latency_ms' => round(max($timings) * 1000, 2), 'official_conformance_test' => false];
file_put_contents($root.'/.runtime/conformance/transport-check.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
echo json_encode($report, JSON_THROW_ON_ERROR)."\n";
exit($passed === 80 && $failures === [] ? 0 : 1);
