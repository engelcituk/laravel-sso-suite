<?php

declare(strict_types=1);

// Local, project-owned normative checks. Never submits to an external service.
$root = dirname(__DIR__);
$output = $root.'/.runtime/conformance';
if (! is_dir($output)) {
    mkdir($output, 0700, true);
}
$junit = $output.'/junit.xml';
if (is_file($junit)) {
    unlink($junit);
}
$args = [PHP_BINARY];
if ($ini = php_ini_loaded_file()) {
    array_push($args, '-c', $ini);
}
array_push($args, $root.'/vendor/phpunit/phpunit/phpunit', '--group', 'conformance', '--testdox', '--no-progress', '--log-junit', $junit);
$process = proc_open($args, [STDIN, STDOUT, STDERR], $pipes, $root);
$exitCode = is_resource($process) ? proc_close($process) : 1;
$report = ['suite' => 'project-owned-oidc-code-profile', 'official_openid_foundation_suite' => false,
    'executed_at_utc' => gmdate(DATE_ATOM), 'php' => PHP_VERSION, 'exit_code' => $exitCode,
    'status' => $exitCode === 0 ? 'passed' : 'failed', 'junit' => '.runtime/conformance/junit.xml'];
if (is_file($junit)) {
    $xml = simplexml_load_file($junit);
    if ($xml !== false && isset($xml->testsuite)) {
        foreach (['tests', 'assertions', 'failures', 'errors', 'skipped'] as $attribute) {
            $report[$attribute] = (int) $xml->testsuite[$attribute];
        }
    }
}
file_put_contents($output.'/result.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo "Local conformance report: .runtime/conformance/result.json (not an OIDF certification result).\n";
exit($exitCode);
