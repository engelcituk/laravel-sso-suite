<?php

declare(strict_types=1);
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;

// Drives the unmodified official modules; results and logs come from the suite API.
require dirname(__DIR__).'/vendor/autoload.php';
if (! getenv('OIDF_CONTAINERIZED')) {
    throw new RuntimeException('Use tools/oidf-docker.ps1 -Action Run so the runner and logout worker share the isolated OP database.');
}
$root = dirname(__DIR__);
$runtime = $root.'/.runtime/conformance';
$label = $argv[1] ?? 'strict';
if (! preg_match('/^[a-z0-9-]+$/', $label)) {
    throw new RuntimeException('Invalid run label.');
}
$directory = getenv('OIDF_RUN_DIRECTORY') ?: 'official-'.$label.'-'.gmdate('Ymd-His');
if (! preg_match('/^official-[a-z0-9-]+$/', $directory)) {
    throw new RuntimeException('Invalid output directory.');
}
$out = $runtime.'/'.$directory;
mkdir($out, 0700, true);
$http = new Client(['base_uri' => 'https://localhost.emobix.co.uk:8443/', 'verify' => $runtime.'/tls/ca.pem', 'proxy' => '', 'timeout' => 40, 'http_errors' => false, 'curl' => getenv('OIDF_CONTAINERIZED') ? [] : [CURLOPT_RESOLVE => ['localhost.emobix.co.uk:8443:127.0.0.1']]]);
$ready = false;
for ($attempt = 0; $attempt < 90; $attempt++) {
    try {
        if ($http->get('api/plan?length=1')->getStatusCode() === 200) {
            $ready = true;
            break;
        }
    } catch (TransferException) {
        // Startup may precede TLS ingress or Mongo readiness.
    }
    sleep(2);
}
if (! $ready) {
    throw new RuntimeException('Official suite API/Mongo did not become ready.');
}
function api(string $method, string $path, array $options = []): array
{
    global $http;
    $response = $http->request($method, $path, $options);
    $body = (string) $response->getBody();
    if ($response->getStatusCode() >= 300) {
        throw new RuntimeException($path.' HTTP '.$response->getStatusCode().': '.substr($body, 0, 500));
    }

    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}
$config = json_decode(file_get_contents($runtime.'/config.json'), true, 512, JSON_THROW_ON_ERROR);
$descriptor = json_decode(file_get_contents($root.'/conformance/oidf-suite.json'), true, 512, JSON_THROW_ON_ERROR);
$plans = array_slice($argv, 2) ?: $descriptor['plans'];
$report = ['suite_revision' => $descriptor['revision'], 'started_at_utc' => gmdate(DATE_ATOM), 'label' => $label, 'plans' => []];
$report['op_runtime'] = ['os' => PHP_OS_FAMILY, 'php' => PHP_VERSION, 'sqlite' => SQLite3::version()['versionString']];
$worker = proc_open([PHP_BINARY, $root.'/tools/oidf-worker.php'], [0 => ['pipe', 'r'], 1 => ['file', $out.'/worker.log', 'a'], 2 => ['file', $out.'/worker.log', 'a']], $pipes, $root);
if (! is_resource($worker)) {
    throw new RuntimeException('Cannot start private fixture logout worker.');
}
fclose($pipes[0]);
$activeId = null;
register_shutdown_function(function () use ($worker, $http, &$activeId): void {
    if ($activeId !== null) {
        try {
            $http->delete('api/runner/'.$activeId);
        } catch (Throwable) {
            // Keep already persisted evidence if the suite has stopped as well.
        }
    }
    proc_terminate($worker);
    proc_close($worker);
});
foreach ($plans as $name) {
    if (is_array($name)) {
        $name = $name['name'];
    }
    $variant = $name === 'oidcc-config-certification-test-plan' ? [] : ['client_registration' => 'static_client'];
    if (in_array($name, ['oidcc-basic-certification-test-plan', 'oidcc-formpost-basic-certification-test-plan'], true)) {
        $variant['server_metadata'] = 'discovery';
    }
    if (str_contains($name, 'logout')) {
        $variant['response_type'] = 'code';
    }
    $plan = api('POST', 'api/plan', ['query' => ['planName' => $name, 'variant' => json_encode((object) $variant)], 'json' => $config]);
    file_put_contents($out.'/'.$plan['id'].'-plan.json', json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo $name.' '.$plan['id'].' ('.count($plan['modules'])." modules)\n";
    $results = [];
    foreach ($plan['modules'] as $module) {
        // Each module starts with an independent browser; reset only this disposable
        // fixture's rate-limit cache so repeated logins exercise the same login route.
        $clear = proc_open([PHP_BINARY, $root.'/tools/oidf-module.php', str_contains($name, 'backchannel') ? 'backchannel' : 'ordinary'], [0 => ['pipe', 'r'], 1 => ['file', $out.'/fixture.log', 'a'], 2 => ['file', $out.'/fixture.log', 'a']], $clearPipes, $runtime.'/op');
        if (! is_resource($clear)) {
            throw new RuntimeException('Cannot reset fixture rate limits.');
        }
        fclose($clearPipes[0]);
        if (proc_close($clear) !== 0) {
            throw new RuntimeException('Fixture cache reset failed.');
        }
        $created = api('POST', 'api/runner', ['query' => ['test' => $module['testModule'], 'plan' => $plan['id'], 'variant' => json_encode($module['variant'] ?? [])]]);
        $id = $created['id'];
        $activeId = $id;
        $started = false;
        $deadline = time() + 120;
        do {
            $info = api('GET', 'api/info/'.$id);
            if ($info['status'] === 'CONFIGURED' && ! $started) {
                api('POST', 'api/runner/'.$id);
                $started = true;
            }
            if (in_array($info['status'], ['FINISHED', 'INTERRUPTED'], true)) {
                break;
            }
            usleep(250000);
        } while (time() < $deadline);
        if (! in_array($info['status'], ['FINISHED', 'INTERRUPTED'], true)) {
            api('DELETE', 'api/runner/'.$id);
            $info = api('GET', 'api/info/'.$id);
        }
        $log = api('GET', 'api/log/'.$id);
        $activeId = null;
        file_put_contents($out.'/'.$id.'-info.json', json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($out.'/'.$id.'-log.json', json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $results[] = ['module' => $module['testModule'], 'variant' => $module['variant'] ?? [], 'id' => $id, 'status' => $info['status'], 'result' => $info['result']];
        echo '  '.$module['testModule'].' '.$info['status'].' '.$info['result']."\n";
        // Persist after each module so interruptions never erase already obtained evidence.
        $report['plans'][$name] = ['id' => $plan['id'], 'modules' => $results];
        file_put_contents($out.'/summary.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    foreach (['export' => '-export.zip', 'exporthtml' => '-html.zip'] as $kind => $suffix) {
        $export = $http->get('api/plan/'.$kind.'/'.$plan['id']);
        if ($export->getStatusCode() !== 200) {
            throw new RuntimeException('Official export failed.');
        }
        file_put_contents($out.'/'.$plan['id'].$suffix, (string) $export->getBody());
    }
}
$report['finished_at_utc'] = gmdate(DATE_ATOM);
file_put_contents($out.'/summary.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo 'Evidence: '.$out."\n";
$unsuccessful = 0;
foreach ($report['plans'] as $plan) {
    foreach ($plan['modules'] as $module) {
        $unsuccessful += $module['status'] !== 'FINISHED' || ! in_array($module['result'], ['PASSED', 'SKIPPED'], true) ? 1 : 0;
    }
}
exit($unsuccessful > 0 ? 1 : 0);
