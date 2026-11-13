<?php

declare(strict_types=1);

// Local semantic review of genuine official exports. Never edits suite results.
require dirname(__DIR__).'/vendor/autoload.php';
$root = dirname(__DIR__);
$summaryFile = realpath($argv[1] ?? '');
$privateRoot = realpath($root.'/.runtime/conformance');
if ($summaryFile === false || $privateRoot === false || ! str_starts_with($summaryFile, $privateRoot.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Provide a private runner summary.json.');
}
$summary = json_decode(file_get_contents($summaryFile), true, 512, JSON_THROW_ON_ERROR);
if (! isset($summary['finished_at_utc'])) {
    throw new RuntimeException('Wait until all plans finish.');
}
function dom(string $html): array
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return [$document, new DOMXPath($document)];
}
function postForm(DOMXPath $xpath, string $path, array $fields): bool
{
    foreach ($xpath->query('//form') as $form) {
        $action = parse_url($form->getAttribute('action'));
        $trusted = is_array($action) && ($action['path'] ?? '') === $path
            && ! isset($action['user']) && ! isset($action['pass']) && ! isset($action['fragment']) && ! isset($action['query'])
            && (! isset($action['host']) || (($action['scheme'] ?? '') === 'https' && $action['host'] === 'identity.sso.test' && ($action['port'] ?? 443) === 9443));
        if (strtolower($form->getAttribute('method')) !== 'post' || ! $trusted) {
            continue;
        }
        foreach ($fields as $name => $type) {
            $nodes = $xpath->query('.//input[@name="'.$name.'"]', $form);
            if ($nodes->length !== 1 || strtolower($nodes->item(0)->getAttribute('type')) !== $type) {
                continue 2;
            }
            if ($type === 'hidden' && $nodes->item(0)->getAttribute('value') === '') {
                continue 2;
            }
        }

        return true;
    }

    return false;
}
$report = ['suite_revision' => $summary['suite_revision'], 'checked_at_utc' => gmdate(DATE_ATOM),
    'official_results_modified' => false, 'official_certification_claimed' => false, 'reviews' => [], 'skips' => []];
foreach ($summary['plans'] as $planName => $plan) {
    $zipPath = dirname($summaryFile).'/'.$plan['id'].'-export.zip';
    $zip = new ZipArchive;
    if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
        throw new RuntimeException('Missing or invalid official export.');
    }
    foreach ($plan['modules'] as $module) {
        if (! in_array($module['result'], ['REVIEW', 'SKIPPED'], true)) {
            continue;
        }
        $entry = $zip->getFromName('test-log-'.$module['module'].'-'.$module['id'].'.json');
        if ($entry === false) {
            throw new RuntimeException('Missing module export.');
        }
        $export = json_decode($entry, true, 512, JSON_THROW_ON_ERROR);
        $events = $export['results'];
        $row = ['module' => $module['module'], 'id' => $module['id'], 'plan_id' => $plan['id'],
            'official_result' => $module['result'], 'export_sha256' => hash_file('sha256', $zipPath)];
        if ($module['result'] === 'SKIPPED') {
            $metadata = [];
            foreach ($events as $event) {
                if (($event['src'] ?? '') === 'GetDynamicServerConfiguration' && isset($event['issuer'])) {
                    $metadata = $event;
                    break;
                }
            }
            $reason = match ($module['module']) {
                'oidcc-scope-address' => ! in_array('address', $metadata['scopes_supported'] ?? [], true) ? 'address not advertised' : null,
                'oidcc-scope-phone' => ! in_array('phone', $metadata['scopes_supported'] ?? [], true) ? 'phone not advertised' : null,
                'oidcc-scope-all' => ! in_array('address', $metadata['scopes_supported'] ?? [], true) && ! in_array('phone', $metadata['scopes_supported'] ?? [], true) ? 'address and phone not advertised' : null,
                'oidcc-refresh-token' => ! in_array('refresh_token', $metadata['grant_types_supported'] ?? [], true) ? 'refresh grant not advertised' : null,
                'oidcc-unsigned-request-object-supported-correctly-or-rejected-as-unsupported' => ($metadata['request_parameter_supported'] ?? null) === false ? 'Request Objects explicitly unsupported' : null,
                default => null,
            };
            if ($metadata === [] || $reason === null) {
                throw new RuntimeException('Unjustified skip: '.$module['module']);
            }
            $row['reason'] = $reason;
            $row['capability_gate_verified'] = true;
            $report['skips'][] = $row;

            continue;
        }
        $pages = array_values(array_filter($events, fn ($event) => isset($event['page_source'])));
        $isLogin = in_array($module['module'], ['oidcc-prompt-login', 'oidcc-max-age-1'], true);
        $isSuccess = in_array($module['module'], ['oidcc-rp-initiated-logout-no-params', 'oidcc-rp-initiated-logout-no-post-logout-redirect-uri', 'oidcc-rp-initiated-logout-only-state'], true);
        $invalid = ['oidcc-ensure-registered-redirect-uri', 'oidcc-ensure-request-object-with-redirect-uri',
            'oidcc-rp-initiated-logout-bad-post-logout-redirect-uri', 'oidcc-rp-initiated-logout-modified-id-token-hint',
            'oidcc-rp-initiated-logout-no-id-token-hint', 'oidcc-rp-initiated-logout-query-added-to-post-logout-redirect-uri',
            'oidcc-rp-initiated-logout-bad-id-token-hint'];
        if (! $isLogin && ! $isSuccess && ! in_array($module['module'], $invalid, true)) {
            throw new RuntimeException('Unclassified review: '.$module['module']);
        }
        $checks = ['finished' => $module['status'] === 'FINISHED', 'captured_dom' => $pages !== [],
            'no_failed_conditions' => ! array_filter($events, fn ($event) => in_array($event['result'] ?? '', ['FAILURE', 'WARNING'], true))];
        foreach ($pages as $page) {
            [$document, $xpath] = dom($page['page_source']);
            $checks['capture_matches'] = preg_match('~'.str_replace('~', '\\~', $page['matched_regexp']).'~u', $document->textContent) === 1;
            if ($isLogin) {
                $checks['post_login_with_csrf'] = postForm($xpath, '/login', ['email' => 'email', 'password' => 'password', '_token' => 'hidden']);
                $checks['password_not_prefilled'] = $xpath->evaluate('string(//input[@name="password"]/@value)') === '';
                $checks['two_login_submissions'] = count(array_filter($events, fn ($event) => ($event['msg'] ?? '') === 'Clicking an element' && ($event['task'] ?? '') === 'Central login')) >= 2;
            } elseif ($isSuccess) {
                $checks['logout_completed'] = str_contains($document->textContent, 'Sesión global cerrada.');
                $checks['no_redirect_form'] = $xpath->query('//form')->length === 0;
                $checks['confirmed_from_csrf_form'] = false;
                foreach ($events as $event) {
                    if (isset($event['response_content'])) {
                        [, $initial] = dom($event['response_content']);
                        $checks['confirmed_from_csrf_form'] = $checks['confirmed_from_csrf_form'] || postForm($initial, '/oidc/logout/confirm', ['_token' => 'hidden', 'transaction' => 'hidden']);
                    }
                }
                $checks['confirmation_clicked'] = (bool) array_filter($events, fn ($event) => ($event['msg'] ?? '') === 'Clicking an element' && ($event['task'] ?? '') === 'Confirm global logout');
            } else {
                $body = trim($xpath->evaluate('string(//body)'));
                $error = json_decode($body, true);
                $checks['explicit_invalid_request'] = is_array($error) && ($error['error'] ?? null) === 'invalid_request';
                $checks['no_redirect_form_or_script'] = $xpath->query('//form|//script')->length === 0;
                $checks['http_400_observed'] = (bool) array_filter($events, fn ($event) => ($event['response_status_code'] ?? null) === 400);
            }
        }
        if (in_array(false, $checks, true)) {
            throw new RuntimeException('Review needs attention: '.$module['module'].' '.json_encode($checks));
        }
        $row['checks'] = $checks;
        $row['local_disposition'] = 'evidence_verified';
        $report['reviews'][] = $row;
    }
    $zip->close();
}
file_put_contents(dirname($summaryFile).'/review-audit.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
if (($argv[2] ?? null) === '--export') {
    file_put_contents($root.'/conformance/oidf-review.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
}
echo count($report['reviews']).' reviews and '.count($report['skips'])." capability skips verified; official statuses preserved.\n";
