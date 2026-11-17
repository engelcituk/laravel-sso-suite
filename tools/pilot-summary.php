<?php

declare(strict_types=1);

// Fail closed before writing any pilot-readiness claim. Only selected, non-secret fields leave .runtime.
$root = dirname(__DIR__);
$private = $root.'/.runtime/pilot-preparation-20261117';
$results = $root.'/.runtime/deployment-lab/results';
$read = static function (string $path): array {
    if (! is_file($path)) {
        throw new RuntimeException('Missing pilot evidence: '.basename($path));
    }

    return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
};
$require = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$host = $read($private.'/workspace-fingerprint.json');
$op = $read($private.'/oidf-runtime-fingerprint.json');
$deployment = $read($private.'/deployment-runtime-fingerprint.json');
$require($host['source_sha256'] === $op['source_sha256'] && $host['source_sha256'] === $deployment['source_sha256']
    && $host['deployment_source_sha256'] === $deployment['deployment_source_sha256'], 'Runtime/workspace mismatch.');
$require($host['deployment_source_sha256'] === (require __DIR__.'/deployment-source.php')($root), 'Workspace changed after validation.');
$require($host['migration_files'] === ['server' => 1, 'client' => 1, 'contracts' => 0], 'Package migration count changed.');
$official = $read($root.'/conformance/oidf-results.json');
$review = $read($root.'/conformance/oidf-review.json');
$require($official['label'] === 'pilot-verified' && count($official['plans']) === 5
    && $official['totals'] === ['PASSED' => 58, 'FAILED' => 0, 'WARNING' => 0, 'REVIEW' => 16, 'SKIPPED' => 10, 'UNKNOWN' => 0]
    && $official['status_counts'] === ['FINISHED' => 84], 'Incomplete official OIDC repetition.');
$require(! $review['official_results_modified'] && count($review['reviews']) === 16 && count($review['skips']) === 10,
    'Missing review/capability evidence.');
foreach ($review['reviews'] as $item) {
    $require($item['local_disposition'] === 'evidence_verified' && ! in_array(false, $item['checks'], true), 'Unverified official review.');
}
$transport = $read($private.'/oidf-transport-check.json');
$require($transport['passed'] === 80 && $transport['failures'] === [], 'OIDC HTTPS transport failed.');
$operational = $read($root.'/conformance/pilot-deployment-results.json');
$require($operational['source_sha256'] === $host['deployment_source_sha256'] && $operational['soak']['passed']
    && $operational['soak']['requested_seconds'] >= 1800 && $operational['soak']['elapsed_seconds'] >= 1800
    && $operational['soak']['totals']['unexpected'] === 0 && $operational['browsers']['failed'] === 0,
    'A complete current 30-minute campaign is required.');
$expectedMatrix = ['chromium/apache', 'chromium/fpm', 'firefox/apache', 'firefox/fpm', 'webkit/apache', 'webkit/fpm'];
$phases = [];
$artifacts = [];
$checks = 0;
$expectedChecks = ['local-before' => 7, 'sso' => 5, 'sso-outage' => 3, 'sso-recovery' => 6, 'local-after' => 8];
foreach (['local-before', 'sso', 'sso-outage', 'sso-recovery', 'local-after'] as $phase) {
    $file = $results.'/pilot-modes-final-'.$phase.'.json';
    $evidence = $read($file);
    $require($evidence['phase'] === $phase && $evidence['failed'] === 0 && count($evidence['tests']) === 6
        && ! $evidence['https_errors_ignored'] && isset($evidence['finished_at_utc'])
        && $evidence['script_sha256'] === hash_file('sha256', __DIR__.'/deployment-mode-browser.cjs'), 'Incomplete current mode phase.');
    $matrix = [];
    foreach ($evidence['tests'] as $test) {
        $require($test['passed'] && count($test['checks']) === $expectedChecks[$phase], 'Failed or incomplete mode browser.');
        $matrix[] = $test['engine'].'/'.$test['profile'];
        $checks += count($test['checks']);
    }
    sort($matrix);
    $require($matrix === $expectedMatrix, 'Mode browser matrix mismatch.');
    $phases[$phase] = $evidence;
    $artifacts[basename($file)] = hash_file('sha256', $file);
}
$preservation = [];
$upgrades = [];
foreach (['fpm', 'apache'] as $profile) {
    foreach (['one', 'two'] as $role) {
        $file = $results.'/modes-preservation-'.$profile.'-'.$role.'.json';
        $evidence = $read($file);
        $require($evidence['passed'] && $evidence['schema_unchanged'] && $evidence['all_user_attributes_unchanged']
            && $evidence['passwords_and_remember_tokens_unchanged'] && $evidence['acl_unchanged']
            && strtotime($evidence['checked_at_utc']) >= strtotime($phases['local-after']['finished_at_utc']), 'Account preservation not verified after modes.');
        $preservation[$profile][$role] = $evidence;
        $artifacts[basename($file)] = hash_file('sha256', $file);
    }
    $file = $results.'/pilot-upgrade-'.$profile.'.json';
    $evidence = $read($file);
    foreach (['passed', 'isolated_namespace', 'recorded_migration_not_reapplied', 'adaptation_only_sso_tables',
        'legacy_sessions_revoked', 'logout_receipts_preserved', 'explicit_link_idempotent',
        'users_schema_and_all_attributes_unchanged', 'local_password_valid_and_unchanged', 'acl_unchanged'] as $flag) {
        $require($evidence[$flag] === true, 'Upgrade preservation check failed: '.$flag);
    }
    $require($evidence['new_package_migrations'] === 0 && ! $evidence['production_upgrade_tool'], 'Unexpected upgrade scope.');
    $upgrades[$profile] = $evidence;
    $artifacts[basename($file)] = hash_file('sha256', $file);
}
$negative = $read($private.'/modes-negative-control.json');
$require($negative['passed'] && $negative['negative_exit'] === 1 && $negative['positive_exit'] === 0
    && ! $negative['changed_fixture_data'], 'Mode validator must detect a deliberate evidence mismatch.');
$exportNegative = $read($private.'/export-negative-control.json');
$require($exportNegative['passed'] && $exportNegative['historical_evidence_rejected']
    && $exportNegative['exit'] !== 0 && ! $exportNegative['success_report_written'], 'Historical evidence must not be exported as current.');
$before = $read($private.'/protected-files-before.json');
$after = $read($private.'/protected-files-after.json');
$beforeByPath = array_column($before, 'sha256', 'path');
$afterByPath = array_column($after, 'sha256', 'path');
ksort($beforeByPath);
ksort($afterByPath);
$require($beforeByPath === $afterByPath && count($before) > 0, 'Example data or secrets changed.');
$quality = $read($private.'/quality-summary.json');
$require($quality['phpunit']['passed'] && $quality['larastan']['passed'] && $quality['pint_changed_files']['passed'], 'Changed code quality failed.');
$cleanup = $read($private.'/cleanup.json');
$require($cleanup['deployment_stopped'] && $cleanup['oidf_stopped'] && $cleanup['remaining_task_containers'] === 0,
    'Task-owned laboratories must be stopped before final delivery.');
foreach (['workspace-fingerprint.json', 'oidf-runtime-fingerprint.json', 'deployment-runtime-fingerprint.json',
    'oidf-transport-check.json', 'modes-negative-control.json', 'export-negative-control.json',
    'protected-files-before.json', 'protected-files-after.json', 'quality-summary.json', 'cleanup.json'] as $file) {
    $artifacts[$file] = hash_file('sha256', $private.'/'.$file);
}
$report = ['schema_version' => 1, 'checked_at_utc' => gmdate(DATE_ATOM), 'base_commit' => '98b6e47f62f530b110f647f82a7797b9d3bfa141',
    'scope' => 'Verified local preparation for an external pilot; not the external pilot itself', 'passed' => true,
    'source_fingerprints' => ['workspace' => $host, 'official_op' => $op, 'deployments' => $deployment],
    'official_oidc' => ['results' => 'conformance/oidf-results.json', 'results_sha256' => hash_file('sha256', $root.'/conformance/oidf-results.json'),
        'review_sha256' => hash_file('sha256', $root.'/conformance/oidf-review.json'), 'totals' => $official['totals'], 'transport' => $transport],
    'operational_evidence' => ['path' => 'conformance/pilot-deployment-results.json',
        'sha256' => hash_file('sha256', $root.'/conformance/pilot-deployment-results.json')],
    'modes' => ['cases' => 30, 'checks' => $checks, 'phases' => $phases, 'preservation' => $preservation, 'negative_control' => $negative],
    'existing_installation_rehearsal' => $upgrades, 'export_negative_control' => $exportNegative, 'quality' => $quality, 'cleanup' => $cleanup,
    'preserved_examples' => ['files_checked' => count($before), 'contents_unchanged' => true, 'credentials_exported' => false],
    'migration_files' => $host['migration_files'], 'published_versions' => false, 'external_certification_claimed' => false,
    'artifacts_sha256' => $artifacts,
    'limits' => ['Local isolated Docker only', 'No independent external review/certification',
        'No physical mobile devices, commercial browsers or Safari on macOS/iOS', 'No distributed or multiday load',
        'Upgrade rehearsal is not a production upgrade tool', 'Full-repository Pint retains documented pre-existing findings']];
file_put_contents($root.'/conformance/pilot-preparation-results.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo 'Verified pilot preparation: five OIDC plans, 30 mode cases, two upgrade rehearsals and a complete current 30-minute campaign.'."\n";
