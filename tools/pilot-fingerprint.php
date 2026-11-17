<?php

declare(strict_types=1);

// Source and dependency inventory only: never reads .env, databases or key material.
$root = dirname(__DIR__);
$files = [];
foreach (['packages', 'examples/shared', 'examples/identity-server/routes',
    'examples/identity-server/resources', 'examples/identity-server/database/migrations'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $files[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = hash_file('sha256', $file->getPathname());
        }
    }
}
foreach (array_merge(glob($root.'/tools/oidf*'), [$root.'/examples/identity-server/bootstrap/providers.php',
    $root.'/vendor/composer/installed.json', $root.'/conformance/oidf-suite.json']) as $file) {
    if (is_file($file)) {
        $files[str_replace('\\', '/', substr($file, strlen($root) + 1))] = hash_file('sha256', $file);
    }
}
ksort($files);
echo json_encode(['checked_at_utc' => gmdate(DATE_ATOM), 'source_sha256' => hash('sha256',
    json_encode($files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 'file_count' => count($files),
    'deployment_source_sha256' => is_dir($root.'/conformance/deployment') && is_file($root.'/composer.lock')
        ? (require $root.'/tools/deployment-source.php')($root) : null,
    'dependency_inventory_sha256' => $files['vendor/composer/installed.json'],
    'migration_files' => ['server' => count(glob($root.'/packages/server/database/migrations/*.php')),
        'client' => count(glob($root.'/packages/client/database/migrations/*.php')), 'contracts' => 0]],
    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
