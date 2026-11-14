<?php

declare(strict_types=1);

// Only source/configuration; keys, credentials, database files and results are excluded.
return static function (string $root): string {
    $files = [];
    foreach (['packages', 'tests'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['php', 'json'], true)) {
                $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $files[$path] = hash_file('sha256', $file->getPathname());
            }
        }
    }
    foreach (['composer.lock', 'phpunit.xml', 'phpunit.database.xml', 'tools/database-lab.ps1',
        'tools/database-fingerprint.php', 'tools/database-summary.php',
        'conformance/database/Dockerfile', 'conformance/database/compose.yml'] as $path) {
        $files[$path] = hash_file('sha256', $root.'/'.$path);
    }
    ksort($files);

    return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
};
