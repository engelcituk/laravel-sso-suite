<?php

declare(strict_types=1);

return static function (string $root): string {
    $files = [];
    foreach (['packages', 'conformance/deployment'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = hash_file('sha256', $file->getPathname());
            }
        }
    }
    foreach (array_merge(glob($root.'/tools/deployment*'), [$root.'/composer.lock']) as $file) {
        if (is_file($file)) {
            $files[str_replace('\\', '/', substr($file, strlen($root) + 1))] = hash_file('sha256', $file);
        }
    }
    ksort($files);

    return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
};
