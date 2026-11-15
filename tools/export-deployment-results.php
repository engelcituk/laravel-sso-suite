<?php

declare(strict_types=1);

// Exports only successful local evidence. It never contacts a public service.
$root = dirname(__DIR__);
$label = $argv[1] ?? 'soak-30m-verified';
if (! preg_match('/^[a-z0-9-]+$/D', $label)) {
    throw new RuntimeException('Invalid run label.');
}
$directory = $root.'/.runtime/deployment-lab/results';
$read = static fn ($name) => json_decode(file_get_contents($directory.'/'.$name), true, flags: JSON_THROW_ON_ERROR);
$soak = $read($label.'-soak.json');
$browsers = $read('final-browsers.json');
$inspection = $read('deployment-inspection.json');
$proxy = $read('proxy-check.json');
$source = (require __DIR__.'/deployment-source.php')($root);
if (! $soak['passed'] || $soak['elapsed_seconds'] < 1800 || $soak['requested_seconds'] < 1800
    || $soak['failed_workers'] !== 0 || count($soak['workers']) !== 8
    || $soak['source_sha256'] !== $source || $inspection['source_sha256'] !== $source) {
    throw new RuntimeException('A complete passing 30-minute run matching current source is required.');
}
if ($browsers['failed'] !== 0 || count($browsers['tests']) !== 6 || $browsers['https_errors_ignored']
    || $browsers['browser_script_sha256'] !== hash_file('sha256', __DIR__.'/deployment-browser.cjs')) {
    throw new RuntimeException('Six current browser cases with strict TLS must pass.');
}
if (! $proxy['passed'] || count($proxy['checks']) !== 12 || $proxy['https_errors_ignored']
    || $proxy['checker_sha256'] !== hash_file('sha256', __DIR__.'/check-deployment-proxy.php')) {
    throw new RuntimeException('Twelve current trusted-proxy checks must pass with strict TLS.');
}
$combinations = [];
foreach ($browsers['tests'] as $test) {
    if (! $test['passed'] || count($test['checks']) !== 10 || count(array_unique($test['registrable_sites'])) !== 3
        || $test['iframe_fetch_site'] !== 'cross-site' || $test['credentials_submissions'] !== 1) {
        throw new RuntimeException('Incomplete real-browser cross-site evidence.');
    }
    $combinations[] = $test['engine'].'/'.$test['profile'];
}
sort($combinations);
if ($combinations !== ['chromium/apache', 'chromium/fpm', 'firefox/apache', 'firefox/fpm', 'webkit/apache', 'webkit/fpm']) {
    throw new RuntimeException('Unexpected browser/deployment matrix.');
}
$report = ['version' => 1, 'checked_at_utc' => gmdate(DATE_ATOM), 'source_sha256' => $source,
    'official_conformance_claimed' => false, 'images' => $read('images.json'), 'deployments' => [],
    'browsers' => array_intersect_key($browsers, array_flip(['started_at_utc', 'finished_at_utc', 'playwright', 'browser_script_sha256', 'https_errors_ignored', 'tests', 'failed'])),
    'soak' => array_intersect_key($soak, array_flip(['started_at_utc', 'finished_at_utc', 'requested_seconds', 'elapsed_seconds', 'virtual_users', 'events', 'pause_observations', 'final_outbox', 'failed_workers', 'passed'])),
    'proxy_checks' => $proxy, 'resources' => [], 'artifacts_sha256' => []];
$report['browsers']['tests'] = array_map(static fn ($test) => array_intersect_key($test,
    array_flip(['engine', 'profile', 'version', 'passed', 'checks', 'cookie_attributes', 'registrable_sites', 'iframe_fetch_site', 'credentials_submissions'])), $browsers['tests']);
$tls = $root.'/.runtime/deployment-lab/tls';
$ca = openssl_x509_parse(file_get_contents($tls.'/ca.pem'));
$server = openssl_x509_parse(file_get_contents($tls.'/server.pem'));
if ($ca['serialNumberHex'] === $server['serialNumberHex'] || ltrim($ca['serialNumberHex'], '0') === '' || ltrim($server['serialNumberHex'], '0') === ''
    || openssl_x509_verify(file_get_contents($tls.'/server.pem'), openssl_pkey_get_public(file_get_contents($tls.'/ca.pem'))) !== 1) {
    throw new RuntimeException('Invalid private laboratory certificate chain or reused serial.');
}
$report['tls'] = ['ca_sha256' => hash_file('sha256', $tls.'/ca.pem'), 'server_sha256' => hash_file('sha256', $tls.'/server.pem'),
    'ca_serial_hex' => $ca['serialNumberHex'], 'server_serial_hex' => $server['serialNumberHex'], 'host_trust_store_changed' => false];
foreach (['fpm', 'apache'] as $profile) {
    foreach (['op', 'one', 'two'] as $role) {
        $app = $inspection['profiles'][$profile][$role];
        $health = $app['health'];
        if (! $health['secure'] || ! $health['configuration_cached'] || ! $health['routes_cached'] || ! $health['opcache'] || ! $app['doctor']['passed']) {
            throw new RuntimeException('The optimized deployment or doctor failed.');
        }
        if ($health['sapi'] !== ($profile === 'fpm' ? 'fpm-fcgi' : 'apache2handler')
            || $health['driver'] !== ($profile === 'fpm' ? 'mysql' : 'pgsql')
            || $health['session_driver'] !== ($profile === 'fpm' ? 'file' : 'database')) {
            throw new RuntimeException('Unexpected SAPI/database/session deployment matrix.');
        }
        if ($role === 'op' && $app['state']['pending_deliveries'] !== 0) {
            throw new RuntimeException('The resumed outbox has not drained.');
        }
        $report['deployments'][$profile][$role] = $app;
    }
}
$totals = ['requests' => 0, 'cycles' => 0, 'unexpected' => 0, 'outage_denials' => 0, 'logout_denials' => 0, 'recoveries' => 0, 'remember_recoveries' => 0, 'transition_503' => 0, 'transition_callback_400' => 0, 'credential_submissions' => 0];
foreach ($soak['workers'] as $worker) {
    if (! $worker['finished'] || $worker['unexpected_count'] !== 0 || ! $worker['pause_logout']) {
        throw new RuntimeException('A virtual user failed or did not verify logout while the worker was paused.');
    }
    foreach ($totals as $name => $_) {
        if (isset($worker[$name]) && is_int($worker[$name])) {
            $totals[$name] += $worker[$name];
        }
    }
    foreach ($worker['http'] as $operation => $metric) {
        $totals['requests'] += $metric['count'];
        $target = &$report['soak']['profiles'][$worker['profile']]['http'][$operation];
        $target ??= ['count' => 0, 'total_ms' => 0, 'statuses' => [], 'maximum_worker_p95_ms' => 0, 'maximum_worker_p99_ms' => 0, 'max_ms' => 0];
        $target['count'] += $metric['count'];
        $target['total_ms'] += $metric['total_ms'];
        $target['maximum_worker_p95_ms'] = max($target['maximum_worker_p95_ms'], $metric['p95_ms']);
        $target['maximum_worker_p99_ms'] = max($target['maximum_worker_p99_ms'], $metric['p99_ms']);
        $target['max_ms'] = max($target['max_ms'], $metric['max_ms']);
        foreach ($metric['statuses'] as $status => $count) {
            $target['statuses'][$status] = ($target['statuses'][$status] ?? 0) + $count;
        }
        unset($target);
    }
}
$totals['requests_per_second'] = $totals['requests'] / $soak['elapsed_seconds'];
$report['soak']['totals'] = $totals;
foreach ($report['soak']['profiles'] as &$profile) {
    foreach ($profile['http'] as &$metric) {
        $metric['mean_ms'] = $metric['total_ms'] / $metric['count'];
        unset($metric['total_ms']);
    }
    unset($metric);
}
unset($profile);
$mib = static function (string $value): float {
    if (! preg_match('/^([\d.]+)([KMGT]?i?B)/', trim($value), $parts)) {
        throw new RuntimeException('Unknown memory unit.');
    }
    $multipliers = ['B' => 1 / 1048576, 'kB' => 1 / 1024, 'KB' => 1 / 1024, 'KiB' => 1 / 1024, 'MB' => 1, 'MiB' => 1, 'GB' => 1024, 'GiB' => 1024];

    return (float) $parts[1] * $multipliers[$parts[2]];
};
foreach (file($directory.'/'.$label.'-stats.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    $stats = $row['stats'];
    $memory = $mib(explode('/', $stats['MemUsage'])[0]);
    $target = &$report['resources'][$stats['Name']];
    $target ??= ['samples' => 0, 'first_at_utc' => $row['checked_at_utc'], 'initial_mib' => $memory, 'peak_mib' => 0, 'peak_cpu_percent' => 0, 'peak_pids' => 0];
    $target['samples']++;
    $target['last_at_utc'] = $row['checked_at_utc'];
    $target['final_mib'] = $memory;
    $target['peak_mib'] = max($target['peak_mib'], $memory);
    $target['peak_cpu_percent'] = max($target['peak_cpu_percent'], (float) $stats['CPUPerc']);
    $target['peak_pids'] = max($target['peak_pids'], (int) $stats['PIDs']);
    unset($target);
}
if (count($report['resources']) !== 5) {
    throw new RuntimeException('All five server/database containers require telemetry.');
}
foreach ($report['resources'] as $resource) {
    if ($resource['samples'] < 25 || strtotime($resource['last_at_utc']) - strtotime($resource['first_at_utc']) < 1440) {
        throw new RuntimeException('Telemetry does not cover the campaign.');
    }
}
foreach ([$label.'-soak.json', $label.'-stats.jsonl', 'final-browsers.json', 'deployment-inspection.json', 'images.json', 'proxy-check.json'] as $artifact) {
    $report['artifacts_sha256'][$artifact] = hash_file('sha256', $directory.'/'.$artifact);
}
file_put_contents($root.'/conformance/deployment-results.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo "Verified two optimized deployments, six browser cases and a complete 30-minute campaign; exported only non-secret evidence.\n";
