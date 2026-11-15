<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/deployment-http.php';
if (getenv('SSO_DEPLOYMENT_LAB') !== '1' || dirname(__DIR__) !== '/app' || ! extension_loaded('pcntl')) {
    throw new RuntimeException('Use the isolated PCNTL deployment laboratory.');
}
$seconds = (int) ($argv[1] ?? 1800);
$label = $argv[2] ?? 'evaluation';
if ($seconds < 30 || $seconds > 7200 || ! preg_match('/^[a-z0-9-]+$/D', $label)) {
    throw new RuntimeException('Invalid bounded soak duration or label.');
}
$manifest = json_decode(file_get_contents('/app/.runtime/deployment-lab/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
foreach (['fpm-down', 'pause-worker'] as $marker) {
    if (is_file('/control/'.$marker)) {
        unlink('/control/'.$marker);
    }
}
$started = hrtime(true);
$deadline = $started + $seconds * 1000000000;
$outageStart = (int) floor($seconds / 6);
$outageEnd = $outageStart + min(60, max(3, (int) floor($seconds / 15)));
$report = ['started_at_utc' => gmdate(DATE_ATOM), 'requested_seconds' => $seconds, 'virtual_users' => 8,
    'source_sha256' => (require __DIR__.'/deployment-source.php')('/app'), 'events' => [], 'workers' => []];
$children = [];
$deliveryProcesses = [];
foreach (array_keys($manifest['profiles']) as $name) {
    $deliveryProcesses[] = proc_open([PHP_BINARY, __DIR__.'/deployment-control.php', 'worker', $name, (string) ($seconds + 5)],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/results/'.$label.'-worker-'.$name.'.log', 'a'], 2 => ['file', '/results/'.$label.'-worker-'.$name.'.log', 'a']], $pipes);
}
foreach ($manifest['profiles'] as $name => $profile) {
    for ($userIndex = 0; $userIndex < 4; $userIndex++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork a virtual user.');
        }
        if ($pid !== 0) {
            $children[$pid] = $name.'-'.$userIndex;

            continue;
        }
        // Do not close inherited worker process handles in children (proc_close would wait).
        $browser = new DeploymentBrowser($profile, $profile['users'][$userIndex]);
        $result = ['profile' => $name, 'user' => $userIndex, 'cycles' => 0, 'outage_denials' => 0, 'logout_denials' => 0,
            'recoveries' => 0, 'remember_recoveries' => 0, 'transition_503' => 0, 'transition_callback_400' => 0, 'pause_logout' => false, 'unexpected_count' => 0, 'unexpected' => [], 'credential_submissions' => 0];
        $path = '/results/'.$label.'-'.$name.'-'.$userIndex.'.json';
        $write = function () use (&$result, $browser, $path): void {
            $result['credential_submissions'] = $browser->credentialSubmissions;
            $result['http'] = $browser->summary();
            file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        };
        while (hrtime(true) < $deadline) {
            clearstatcache(); // Markers are changed by another process.
            try {
                if ($name === 'fpm' && is_file('/control/fpm-down')) {
                    foreach (['one', 'two'] as $role) {
                        if ($browser->protected($role) !== 401) {
                            throw new RuntimeException('Access was not denied during the OP outage.');
                        }
                        $result['outage_denials']++;
                    }
                } else {
                    foreach (['one', 'two'] as $role) {
                        $status = $browser->protected($role);
                        if ($status === 401) {
                            $browser->authenticate($role);
                            $result['recoveries']++;
                            $status = $browser->protected($role);
                        }
                        if ($status !== 200) {
                            throw new RuntimeException('Authenticated protected request rejected unexpectedly.');
                        }
                    }
                    if ($result['cycles'] > 0 && $result['cycles'] % min(45, max(3, (int) ($seconds / 40))) === 0) {
                        $browser->cookies->clear(parse_url($profile['op'], PHP_URL_HOST), '/', $name.'_op_session');
                        $browser->authenticate('one');
                        $result['remember_recoveries']++;
                    }
                    if (($result['cycles'] > 0 && $result['cycles'] % min(90, max(5, (int) ($seconds / 20))) === 0)
                        || (is_file('/control/pause-worker') && ! $result['pause_logout'])) {
                        $browser->logout();
                        $result['logout_denials']++;
                        $result['pause_logout'] = $result['pause_logout'] || is_file('/control/pause-worker');
                    }
                }
                $result['cycles']++;
            } catch (Throwable $error) {
                $elapsed = (hrtime(true) - $started) / 1000000000;
                // Explicit OP 503 responses at the two injection boundaries are expected,
                // recorded separately and never counted as successful HTTP operations.
                if ($name === 'fpm' && $error->getCode() === 503 && $elapsed >= $outageStart && $elapsed <= $outageEnd + 3) {
                    $result['transition_503']++;
                } elseif ($name === 'fpm' && $error->getCode() === 400 && $elapsed >= $outageStart && $elapsed <= $outageEnd + 3) {
                    $result['transition_callback_400']++;
                } else {
                    $result['unexpected_count']++;
                }
                if ($result['unexpected_count'] > count($result['unexpected']) && count($result['unexpected']) < 20) {
                    $result['unexpected'][] = ['elapsed_seconds' => round((hrtime(true) - $started) / 1000000000, 3), 'type' => $error::class,
                        'message' => $error instanceof RuntimeException ? $error->getMessage() : 'Unexpected runtime exception'];
                }
            }
            if ($result['cycles'] % 10 === 0 || $result['unexpected'] !== []) {
                $write();
            }
            usleep(2000000);
        }
        $result['finished'] = true;
        $write();
        exit($result['unexpected'] === [] ? 0 : 1);
    }
}
$pauseStart = (int) floor($seconds / 2);
$pauseEnd = $pauseStart + min(120, max(6, (int) floor($seconds / 10)));
$done = [];
while (hrtime(true) < $deadline) {
    $elapsed = (hrtime(true) - $started) / 1000000000;
    foreach ([['outage_begin', $outageStart, 'fpm-down', true], ['outage_end', $outageEnd, 'fpm-down', false],
        ['worker_pause', $pauseStart, 'pause-worker', true], ['worker_resume', $pauseEnd, 'pause-worker', false]] as [$event, $at, $marker, $create]) {
        if ($elapsed >= $at && ! isset($done[$event])) {
            if ($create) {
                touch('/control/'.$marker);
            } else {
                if ($event === 'worker_resume') {
                    foreach (array_keys($manifest['profiles']) as $name) {
                        $process = proc_open([PHP_BINARY, __DIR__.'/deployment-control.php', 'check', $name, 'op'],
                            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/results/'.$label.'-observations.log', 'a']], $pipes);
                        $observation = json_decode(stream_get_contents($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
                        fclose($pipes[1]);
                        proc_close($process);
                        $report['pause_observations'][$name] = $observation['state'];
                    }
                }
                unlink('/control/'.$marker);
            }
            $done[$event] = true;
            $report['events'][] = ['event' => $event, 'elapsed_seconds' => round($elapsed, 3)];
            echo $event.' at '.round($elapsed, 1)."s\n";
        }
    }
    if ((int) $elapsed % 60 === 0) {
        $report['elapsed_seconds'] = round($elapsed, 3);
        file_put_contents('/results/'.$label.'-progress.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }
    usleep(250000);
}
$failed = 0;
foreach ($children as $pid => $name) {
    pcntl_waitpid($pid, $status);
    $failed += ! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0 ? 1 : 0;
    $report['workers'][$name] = json_decode(file_get_contents('/results/'.$label.'-'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
}
foreach ($deliveryProcesses as $process) {
    if (! is_resource($process) || proc_close($process) !== 0) {
        $failed++;
    }
}
foreach (array_keys($manifest['profiles']) as $name) {
    $process = proc_open([PHP_BINARY, __DIR__.'/deployment-control.php', 'check', $name, 'op'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/results/'.$label.'-observations.log', 'a']], $pipes);
    $state = json_decode(stream_get_contents($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
    fclose($pipes[1]);
    $failed += proc_close($process) === 0 ? 0 : 1;
    $report['final_outbox'][$name] = $state['state'];
    if ($state['state']['pending_deliveries'] !== 0) {
        $failed++;
    }
}
$report['finished_at_utc'] = gmdate(DATE_ATOM);
$report['elapsed_seconds'] = round((hrtime(true) - $started) / 1000000000, 3);
$report['failed_workers'] = $failed;
$report['passed'] = $failed === 0 && count($report['workers']) === 8
    && array_sum(array_column($report['workers'], 'outage_denials')) > 0
    && array_sum(array_column($report['workers'], 'logout_denials')) > 0
    && count(array_filter($report['pause_observations'] ?? [], static fn ($state) => $state['pending_deliveries'] > 0)) === 2;
file_put_contents('/results/'.$label.'-soak.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo ($report['passed'] ? 'PASS' : 'FAIL').': '.$report['elapsed_seconds']." seconds with eight isolated HTTP virtual users.\n";
exit($report['passed'] ? 0 : 1);
