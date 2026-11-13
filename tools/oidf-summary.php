<?php

declare(strict_types=1);

// Export only the runner's non-secret result fields for repository documentation.
$root = dirname(__DIR__);
$file = realpath($argv[1] ?? '');
$privateRoot = realpath($root.'/.runtime/conformance');
if ($file === false || $privateRoot === false || ! str_starts_with($file, $privateRoot.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Provide a private .runtime/conformance/.../summary.json.');
}
$source = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$descriptor = json_decode(file_get_contents($root.'/conformance/oidf-suite.json'), true, 512, JSON_THROW_ON_ERROR);
if (! isset($source['finished_at_utc']) || array_keys($source['plans']) !== $descriptor['plans']) {
    throw new RuntimeException('All five plans must finish before exporting the documentation summary.');
}
$report = array_intersect_key($source, array_flip(['suite_revision', 'started_at_utc', 'finished_at_utc', 'label', 'op_runtime']));
$report['official_certification_claimed'] = false;
$report['jar_sha256'] = hash_file('sha256', $root.'/.runtime/oidf-suite/target/fapi-test-suite.jar');
$report['totals'] = array_fill_keys(['PASSED', 'FAILED', 'WARNING', 'REVIEW', 'SKIPPED', 'UNKNOWN'], 0);
$report['status_counts'] = [];
$report['plans'] = [];
foreach ($source['plans'] as $name => $plan) {
    $definition = json_decode(file_get_contents(dirname($file).'/'.$plan['id'].'-plan.json'), true, 512, JSON_THROW_ON_ERROR);
    if (count($plan['modules']) !== count($definition['modules'])) {
        throw new RuntimeException('Not all modules were executed in '.$name);
    }
    $counts = array_fill_keys(['PASSED', 'FAILED', 'WARNING', 'REVIEW', 'SKIPPED', 'UNKNOWN'], 0);
    $modules = [];
    foreach ($plan['modules'] as $module) {
        $counts[$module['result']] = ($counts[$module['result']] ?? 0) + 1;
        $report['totals'][$module['result']] = ($report['totals'][$module['result']] ?? 0) + 1;
        $report['status_counts'][$module['status']] = ($report['status_counts'][$module['status']] ?? 0) + 1;
        $modules[] = array_intersect_key($module, array_flip(['module', 'variant', 'id', 'status', 'result']));
    }
    $report['plans'][$name] = ['id' => $plan['id'], 'counts' => $counts, 'modules' => $modules];
}
file_put_contents($root.'/conformance/oidf-results.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo json_encode($report['totals'], JSON_THROW_ON_ERROR)."\n";
