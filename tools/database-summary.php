<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$run = $argv[1] ?? '';
if (! preg_match('/^[a-z0-9-]+-\d{8}-\d{6}$/D', $run)) {
    throw new RuntimeException('Pass the full run label, without the engine suffix.');
}
$directory = $root.'/.runtime/database-lab/results';
$report = ['checked_at_utc' => gmdate(DATE_ATOM), 'run' => $run, 'official_conformance_claimed' => false, 'engines' => []];
$report['images'] = json_decode(file_get_contents($directory.'/'.$run.'-images.json'), true, flags: JSON_THROW_ON_ERROR);
$fingerprint = require __DIR__.'/database-fingerprint.php';
$currentSource = $fingerprint($root);
foreach (['mysql', 'pgsql'] as $driver) {
    $base = $directory.'/'.$run.'-'.$driver;
    $environment = json_decode(file_get_contents($base.'-environment.json'), true, flags: JSON_THROW_ON_ERROR);
    if ($environment['driver'] !== $driver || $environment['source_sha256'] !== $currentSource || ! $environment['pcntl']) {
        throw new RuntimeException('Engine/source/pcntl evidence does not match the workspace.');
    }
    $xml = new DOMDocument;
    if (! $xml->load($base.'.junit.xml', LIBXML_NONET)) {
        throw new RuntimeException('Invalid JUnit evidence.');
    }
    $xpath = new DOMXPath($xml);
    $suite = $xpath->query('//testsuite[@name="Database"]')->item(0);
    if (! $suite instanceof DOMElement) {
        throw new RuntimeException('Missing full database suite.');
    }
    $counts = [];
    foreach (['tests', 'assertions', 'failures', 'errors', 'skipped'] as $name) {
        $counts[$name] = (int) $suite->getAttribute($name);
    }
    $cases = [];
    $rememberCases = [];
    foreach ($xpath->query('//testcase') as $case) {
        if (str_ends_with($case->getAttribute('class'), '\\Database\\ConcurrencyTest')) {
            $cases[] = $case->getAttribute('name');
        }
        if (str_ends_with($case->getAttribute('class'), '\\Database\\RememberConcurrencyTest')) {
            $rememberCases[] = $case->getAttribute('name');
        }
    }
    if ($counts['tests'] < 190 || $counts['failures'] + $counts['errors'] + $counts['skipped'] !== 0 || count($cases) !== 10 || count($rememberCases) !== 4) {
        throw new RuntimeException('Only complete, passing runs with ten protocol and four remembered-device concurrency cases can be exported.');
    }
    $report['engines'][$driver] = $environment + ['counts' => $counts, 'concurrency_cases' => $cases, 'remember_concurrency_cases' => $rememberCases,
        'maximum_workers' => 8, 'junit_sha256' => hash_file('sha256', $base.'.junit.xml')];
}
$path = $root.'/conformance/database-results.json';
file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo "Verified both complete runs; exported conformance/database-results.json without credentials.\n";
