<?php

declare(strict_types=1);

// Builds only committed package files. No Composer install, Git mutation or application access.
$root = dirname(__DIR__);
$mode = $argv[1] ?? '';
$options = [];
foreach (array_slice($argv, 2) as $argument) {
    if (! preg_match('/^--(source|output|archives-ref|repository)=(.+)$/D', $argument, $match) || isset($options[$match[1]])) {
        throw new InvalidArgumentException('Use build|index --source=COMMIT --output=DIRECTORY [--archives-ref=COMMIT] [--repository=OWNER/REPO].');
    }
    $options[$match[1]] = $match[2];
}
if (! in_array($mode, ['build', 'index'], true) || ! preg_match('/^[a-f0-9]{40}$/D', $options['source'] ?? '')) {
    throw new InvalidArgumentException('An explicit full source commit is required.');
}
$source = $options['source'];
$output = $options['output'] ?? '';
if ($output === '' || ! is_dir($output) || ! is_writable($output)) {
    throw new InvalidArgumentException('Create an empty build directory or use the existing distribution checkout.');
}
$output = realpath($output);
$repository = $options['repository'] ?? 'engelcituk/laravel-sso-suite';
if (! preg_match('~^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$~D', $repository)) {
    throw new InvalidArgumentException('Invalid GitHub repository.');
}
$version = '0.1.x-dev';
$packages = ['contracts', 'server', 'client'];
$json = static fn (array $value): string => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
$git = static function (array $arguments) use ($root): string {
    $process = proc_open(['git', ...$arguments], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot read committed source.');
    }
    $result = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Git source read failed: '.$error);
    }

    return $result;
};
$directory = $output.'/dist/'.$source;
if ($mode === 'build') {
    if (! class_exists(ZipArchive::class) || is_dir($directory)) {
        throw new RuntimeException('ZIP support and a fresh output path are required; existing archives are immutable.');
    }
    if (trim($git(['cat-file', '-t', $source])) !== 'commit') {
        throw new RuntimeException('The source must be a Git commit.');
    }
    $time = (int) trim($git(['show', '-s', '--format=%ct', $source]));
    if (! mkdir($directory, 0755, true)) {
        throw new RuntimeException('Cannot create archive directory.');
    }
    $inventory = ['schema_version' => 1, 'source' => $source, 'version' => $version, 'time' => gmdate(DATE_ATOM, $time), 'packages' => []];
    foreach ($packages as $slug) {
        $prefix = 'packages/'.$slug.'/';
        $files = array_filter(explode("\n", trim($git(['ls-tree', '-r', '--name-only', $source, '--', rtrim($prefix, '/')]))));
        $entries = [];
        foreach ($files as $path) {
            $relative = substr($path, strlen($prefix));
            // Explicit distribution surface: runtime PHP, config, views and the one migration.
            if (! in_array($relative, ['composer.json', 'LICENSE', 'README.md'], true) && ! preg_match('~^(?:src|config|resources/views|database/migrations)/[a-zA-Z0-9_./-]+\.php$~D', $relative)) {
                throw new RuntimeException('Unreviewed package file: '.$path);
            }
            if (str_contains($relative, '..') || str_contains($relative, '//')) {
                throw new RuntimeException('Unsafe archive path.');
            }
            $entries[$relative] = $git(['show', $source.':'.$path]);
        }
        $manifest = json_decode($entries['composer.json'] ?? '', true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['name'] ?? '') !== 'laravel-sso/'.$slug || ($manifest['type'] ?? '') !== 'library'
            || isset($manifest['scripts']) || isset($manifest['repositories']) || isset($manifest['autoload-dev'])) {
            throw new RuntimeException('Unexpected distribution manifest.');
        }
        $migrations = count(array_filter(array_keys($entries), static fn ($path) => str_starts_with($path, 'database/migrations/')));
        if ($migrations !== ($slug === 'contracts' ? 0 : 1)) {
            throw new RuntimeException('Expected exactly one migration per Laravel package.');
        }
        $entries['LICENSE'] ??= $git(['show', $source.':LICENSE']);
        $entries['README.md'] = '# '.$manifest['name']."\n\nExperimental Laravel SSO library.\n\nSource: https://github.com/$repository/tree/$source/packages/$slug\n\nInstallation: https://github.com/$repository/tree/composer#readme\n";
        ksort($entries);
        $zip = new ZipArchive;
        if ($zip->open($directory.'/'.$slug.'.zip', ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Cannot create package archive.');
        }
        foreach ($entries as $path => $contents) {
            $zip->addFromString($path, $contents);
            $zip->setMtimeName($path, $time);
            $zip->setCompressionName($path, ZipArchive::CM_DEFLATE, 9);
            $zip->setExternalAttributesName($path, ZipArchive::OPSYS_UNIX, 0100644 << 16);
        }
        if (! $zip->close()) {
            throw new RuntimeException('Cannot finish package archive.');
        }
        $inventory['packages'][$slug] = ['manifest' => $manifest, 'files' => count($entries), 'migrations' => $migrations,
            'sha1' => hash_file('sha1', $directory.'/'.$slug.'.zip'), 'sha256' => hash_file('sha256', $directory.'/'.$slug.'.zip')];
    }
    file_put_contents($directory.'/manifest.json', $json($inventory));
    echo 'Built three package archives from '.$source."\n";
} else {
    $archivesRef = $options['archives-ref'] ?? '';
    if (! preg_match('/^[a-f0-9]{40}$/D', $archivesRef)) {
        throw new InvalidArgumentException('An immutable archive commit is required, not a branch or tag.');
    }
    $inventory = json_decode(file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    if ($inventory['source'] !== $source || $inventory['version'] !== $version || array_keys($inventory['packages']) !== $packages) {
        throw new RuntimeException('Invalid archive inventory.');
    }
    $index = ['packages' => [], 'available-packages' => []];
    foreach ($inventory['packages'] as $slug => $package) {
        if (! hash_equals($package['sha256'], hash_file('sha256', $directory.'/'.$slug.'.zip'))
            || ! hash_equals($package['sha1'], hash_file('sha1', $directory.'/'.$slug.'.zip'))) {
            throw new RuntimeException('Archive integrity mismatch.');
        }
        $manifest = $package['manifest'];
        $manifest['version'] = $version;
        $manifest['time'] = $inventory['time'];
        $manifest['dist'] = ['type' => 'zip', 'url' => "https://raw.githubusercontent.com/$repository/$archivesRef/dist/$source/$slug.zip",
            'reference' => $source, 'shasum' => $package['sha1']];
        $manifest['support'] = ['source' => "https://github.com/$repository/tree/$source/packages/$slug"];
        $index['packages'][$manifest['name']][$version] = $manifest;
        $index['available-packages'][] = $manifest['name'];
    }
    file_put_contents($output.'/packages.json', $json($index));
    file_put_contents($output.'/.gitattributes', "*.zip binary\n*.json text eol=lf\n*.md text eol=lf\n");
    file_put_contents($output.'/README.md', "# Laravel SSO Composer distribution\n\nDevelopment packages from source commit `$source`. No tags or releases.\n\n".
        "Configure your Laravel host:\n\n```sh\ncomposer config repositories.laravel-sso composer https://raw.githubusercontent.com/$repository/composer\n```\n\n".
        "Server:\n\n```sh\ncomposer require laravel-sso/server:$version laravel-sso/contracts:$version --prefer-dist\n```\n\n".
        "Client:\n\n```sh\ncomposer require laravel-sso/client:$version laravel-sso/contracts:$version --prefer-dist\n```\n\n".
        "Archives are pinned to commit `$archivesRef`; keep composer.lock in your application.\n");
    echo 'Indexed three packages with immutable archive URLs.'."\n";
}
