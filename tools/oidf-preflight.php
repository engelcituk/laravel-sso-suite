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

    return null;
}
$missing = [];
if (executable('docker') === null) {
    $missing[] = 'Docker is not installed; the official container build/runtime cannot start.';
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
$report = ['suite' => 'OpenID Foundation conformance-suite', 'status' => $missing === [] ? 'prerequisites_detected_not_executed' : 'blocked',
    'executed' => false, 'checked_at_utc' => gmdate(DATE_ATOM), 'java_major' => $javaVersion, 'reasons' => $missing,
    'native_build_available' => $javaVersion >= 21 && executable('mvn') !== null && executable('javac') !== null,
    'source_revision' => $revision, 'required_revision' => $descriptor['revision'],
    'note' => 'The official builder image provides Maven/JDK 21; host Java/Maven are not required for Docker builds. Daemon/TLS/browser readiness is not verified by this preflight.',
    'upstream' => 'https://gitlab.com/openid/conformance-suite'];
$output = dirname(__DIR__).'/.runtime/conformance';
if (! is_dir($output)) {
    mkdir($output, 0700, true);
}
file_put_contents($output.'/oidf-preflight.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
exit($missing === [] ? 0 : 2);
