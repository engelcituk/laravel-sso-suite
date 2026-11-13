<?php

declare(strict_types=1);

// Read-only preflight for the official source-build workflow. Does not install, run or publish anything.
function executable(string $name): ?string
{
    foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
        foreach (PHP_OS_FAMILY === 'Windows' ? ['.exe', '.cmd', '.bat', ''] : [''] as $extension) {
            $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$name.$extension;
            if (is_file($path)) {
                return $path;
            }
        }
    }
    if ($name === 'docker' && PHP_OS_FAMILY === 'Windows') {
        foreach ([(getenv('LOCALAPPDATA') ?: '').'/Programs/DockerDesktop/resources/bin/docker.exe',
            (getenv('ProgramFiles') ?: 'C:/Program Files').'/Docker/Docker/resources/bin/docker.exe'] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
    }

    return null;
}

/** @param list<string> $arguments
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function dockerProbe(array $arguments, string $output): array
{
    $stdout = $output.'/docker-probe-stdout.txt';
    $stderr = $output.'/docker-probe-stderr.txt';
    $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']], $pipes);
    if (! is_resource($process)) {
        return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Could not execute Docker CLI.'];
    }
    fclose($pipes[0]);
    $deadline = microtime(true) + 15;
    do {
        $status = proc_get_status($process);
        if (! $status['running']) {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    if ($status['running']) {
        proc_terminate($process);
    }
    $closed = proc_close($process);
    $code = $status['running'] ? 124 : ($status['exitcode'] >= 0 ? $status['exitcode'] : $closed);

    return ['exit_code' => $code, 'stdout' => trim(file_get_contents($stdout)), 'stderr' => trim(file_get_contents($stderr))];
}
$output = dirname(__DIR__).'/.runtime/conformance';
if (! is_dir($output)) {
    mkdir($output, 0700, true);
}
$missing = [];
$docker = executable('docker');
$dockerClient = null;
$dockerEngine = null;
if ($docker === null) {
    $missing[] = 'Docker is not installed; the official container build/runtime cannot start.';
} else {
    $dockerClient = dockerProbe([$docker, '--version'], $output);
    $dockerEngine = dockerProbe([$docker, 'info', '--format', '{{.ServerVersion}}'], $output);
    if ($dockerEngine['exit_code'] !== 0 || $dockerEngine['stdout'] === '') {
        $missing[] = 'Docker is installed but its Linux daemon is unavailable; inspect docker_engine_error and Windows restart requirements.';
    }
}
$java = executable('java');
$javaVersion = null;
if ($java !== null) {
    $process = proc_open([$java, '-version'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($process)) {
        fclose($pipes[0]);
        $version = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        preg_match('/version "(\d+)(?:\.(\d+))?/', $version, $match);
        $javaVersion = isset($match[1]) ? (int) ($match[1] === '1' ? $match[2] : $match[1]) : null;
    }
}
$descriptor = json_decode(file_get_contents(dirname(__DIR__).'/conformance/oidf-suite.json'), true, 512, JSON_THROW_ON_ERROR);
$suite = $argv[1] ?? (getenv('OIDF_SUITE_PATH') ?: dirname(__DIR__).'/.runtime/oidf-suite');
$revision = null;
if (! is_string($suite) || ! is_file($suite.'/pom.xml') || ! is_file($suite.'/docker-compose.yml') || ! is_file($suite.'/builder-compose.yml')) {
    $missing[] = 'Provide an official conformance-suite checkout via OIDF_SUITE_PATH or the first argument.';
} elseif (($git = executable('git')) !== null) {
    $process = proc_open([$git, '-C', $suite, 'rev-parse', 'HEAD'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($process)) {
        fclose($pipes[0]);
        $revision = trim(stream_get_contents($pipes[1]));
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }
    if ($revision !== $descriptor['revision']) {
        $missing[] = 'Official source revision differs from conformance/oidf-suite.json; inspect and pin it explicitly.';
    }
} else {
    $missing[] = 'Git is required to verify the official source revision.';
}
$recordedPath = dirname(__DIR__).'/conformance/oidf-results.json';
$recorded = is_file($recordedPath) ? json_decode(file_get_contents($recordedPath), true, 512, JSON_THROW_ON_ERROR) : null;
$report = ['suite' => 'OpenID Foundation conformance-suite', 'status' => $missing === [] ? 'prerequisites_detected' : 'blocked',
    'executes_modules' => false, 'checked_at_utc' => gmdate(DATE_ATOM), 'java_major' => $javaVersion, 'reasons' => $missing,
    'last_recorded_evaluation' => $recorded === null ? null : [
        'report' => 'conformance/oidf-results.json', 'source_revision' => $recorded['suite_revision'],
        'finished_at_utc' => $recorded['finished_at_utc'], 'counts' => $recorded['totals'],
        'official_certification_claimed' => false,
    ],
    'native_build_available' => $javaVersion >= 21 && executable('mvn') !== null && executable('javac') !== null,
    'source_revision' => $revision, 'required_revision' => $descriptor['revision'],
    'docker_client' => $dockerClient['stdout'] ?? null, 'docker_engine' => $dockerEngine['stdout'] ?? null,
    'docker_engine_error' => $dockerEngine !== null && $dockerEngine['exit_code'] !== 0 ? $dockerEngine['stderr'] : null,
    'note' => 'The official builder provides Maven/JDK 21. Docker daemon connectivity is checked; TLS/DNS/browser/plan readiness is not established by this preflight.',
    'upstream' => 'https://gitlab.com/openid/conformance-suite'];
$output = dirname(__DIR__).'/.runtime/conformance';
if (! is_dir($output)) {
    mkdir($output, 0700, true);
}
file_put_contents($output.'/oidf-preflight.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
exit($missing === [] ? 0 : 2);
