<?php

declare(strict_types=1);

// Real Composer installs into two disposable hosts under .runtime, never user applications.
$root = dirname(__DIR__);
$build = realpath($argv[1] ?? '');
$composer = realpath($argv[2] ?? '');
$remote = $argv[3] ?? null;
if ($remote !== null && ! preg_match('~^https://raw\.githubusercontent\.com/[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+/composer$~D', $remote)) {
    throw new InvalidArgumentException('The optional remote repository must use the public GitHub HTTPS distribution URL.');
}
if (! $build || ! $composer || ! is_file($build.'/packages.json') || ! is_file($composer)) {
    throw new InvalidArgumentException('Usage: php tools/verify-distribution.php BUILD_DIRECTORY COMPOSER_PHAR');
}
$run = $root.'/.runtime/distribution-check/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
mkdir($run.'/repository', 0700, true);
$report = ['passed' => false, 'php' => PHP_VERSION, 'transport' => $remote === null ? 'loopback' : 'GitHub HTTPS',
    'checks' => [], 'fixture_directory' => substr($run, strlen($root) + 1)];
$check = static function (bool $condition, string $name) use (&$report): void {
    if (! $condition) {
        throw new RuntimeException('Distribution check failed: '.$name);
    }
    $report['checks'][] = $name;
};
$command = static function (array $arguments, string $cwd, string $label) use ($run): string {
    $log = $run.'/'.$label.'.log';
    $environment = getenv();
    unset($environment['COMPOSER'], $environment['COMPOSER_VENDOR_DIR'], $environment['COMPOSER_BIN_DIR']);
    $process = proc_open($arguments, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, $cwd, $environment);
    if (! is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Distribution command failed: '.$label.'; inspect '.$log);
    }

    return file_get_contents($log);
};
$server = null;
try {
    $index = json_decode(file_get_contents($build.'/packages.json'), true, flags: JSON_THROW_ON_ERROR);
    $check(array_keys($index['packages']) === ['laravel-sso/contracts', 'laravel-sso/server', 'laravel-sso/client'], 'three_explicit_packages');
    $source = $index['packages']['laravel-sso/contracts']['0.1.x-dev']['dist']['reference'];
    if (! preg_match('/^[a-f0-9]{40}$/D', $source)) {
        throw new RuntimeException('An explicit source commit is required.');
    }
    foreach (['immutable-build' => ['build', '--source='.$source, '--output='.$build],
        'mutable-index' => ['index', '--source='.$source, '--output='.$build, '--archives-ref=main']] as $label => $arguments) {
        $rejected = false;
        try {
            $command([PHP_BINARY, $root.'/tools/build-distribution.php', ...$arguments], $root, $label);
        } catch (RuntimeException) {
            $rejected = true;
        }
        $check($rejected, $label.'_rejected');
    }
    mkdir($run.'/rebuild');
    $command([PHP_BINARY, $root.'/tools/build-distribution.php', 'build', '--source='.$source, '--output='.$run.'/rebuild'], $root, 'rebuild');
    foreach (['contracts', 'server', 'client'] as $slug) {
        $check(hash_file('sha256', $build.'/dist/'.$source.'/'.$slug.'.zip') === hash_file('sha256', $run.'/rebuild/dist/'.$source.'/'.$slug.'.zip'), $slug.'_repeatable_archive');
    }
    file_put_contents($run.'/rebuild/dist/'.$source.'/contracts.zip', 'tamper', FILE_APPEND);
    $rejected = false;
    try {
        $command([PHP_BINARY, $root.'/tools/build-distribution.php', 'index', '--source='.$source,
            '--output='.$run.'/rebuild', '--archives-ref='.$source], $root, 'corrupt-index');
    } catch (RuntimeException) {
        $rejected = true;
    }
    $check($rejected, 'corrupt_archive_rejected');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (! $socket) {
        throw new RuntimeException('Cannot reserve a loopback test port.');
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $origin = 'http://'.$address;
    foreach ($index['packages'] as $name => &$versions) {
        $package = &$versions['0.1.x-dev'];
        $slug = basename($name);
        $source = $package['dist']['reference'];
        $check(preg_match('~^https://raw\.githubusercontent\.com/[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+/[a-f0-9]{40}/dist/'.$source.'/'.$slug.'\.zip$~D', $package['dist']['url']) === 1,
            $slug.'_immutable_https_archive');
        $archive = $build.'/dist/'.$source.'/'.$slug.'.zip';
        $check(hash_equals($package['dist']['shasum'], hash_file('sha1', $archive)), $slug.'_archive_checksum');
        $zip = new ZipArchive;
        $check($zip->open($archive) === true && $zip->locateName('composer.json') !== false && $zip->locateName('LICENSE') !== false
            && $zip->locateName('README.md') !== false, $slug.'_standalone_archive');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $path = $zip->getNameIndex($i);
            if ($path !== 'composer.json' && $path !== 'LICENSE' && $path !== 'README.md'
                && ! preg_match('~^(src|config|resources/views|database/migrations)/[a-zA-Z0-9_./-]+\.php$~D', $path)) {
                throw new RuntimeException('Unexpected archive content.');
            }
        }
        $zip->close();
        copy($archive, $run.'/repository/'.$slug.'.zip');
        if ($remote === null) {
            $package['dist']['url'] = $origin.'/'.$slug.'.zip';
        }
        unset($package);
    }
    unset($versions);
    file_put_contents($run.'/repository/packages.json', json_encode($index, JSON_THROW_ON_ERROR));
    $server = proc_open([PHP_BINARY, '-S', $address, '-t', $run.'/repository'],
        [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $run.'/http.log', 'a'], 2 => ['file', $run.'/http.log', 'a']], $pipes, $root);
    if (! is_resource($server)) {
        throw new RuntimeException('Cannot start loopback distribution fixture.');
    }
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $connection = @stream_socket_client('tcp://'.$address, $errno, $error, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    $check($ready, 'loopback_repository_ready');
    foreach (['server', 'client'] as $role) {
        $base = $run.'/'.$role;
        foreach (['bootstrap/cache', 'config', 'database/migrations', 'routes', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $directory) {
            mkdir($base.'/'.$directory, 0700, true);
        }
        file_put_contents($base.'/composer.json', json_encode(['name' => 'distribution-check/'.$role,
            'require' => ['laravel/framework' => '^13.0', 'laravel-sso/'.$role => '0.1.x-dev', 'laravel-sso/contracts' => '0.1.x-dev'],
            'repositories' => [['type' => 'composer', 'url' => $remote ?? $origin]],
            'config' => ['vendor-dir' => 'vendor', 'bin-dir' => 'vendor/bin', 'secure-http' => $remote !== null, 'allow-plugins' => false]], JSON_THROW_ON_ERROR));
        $command([PHP_BINARY, $composer, 'update', '--no-interaction', '--no-progress', '--no-plugins', '--no-scripts', '--prefer-dist'], $base, $role.'-composer');
        $check(is_file($base.'/vendor/laravel-sso/'.$role.'/src/Sso'.ucfirst($role).'ServiceProvider.php'), $role.'_downloaded_runtime');
        $installed = json_decode(file_get_contents($base.'/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
        $installedNames = array_column($installed['packages'], 'name');
        $other = $role === 'client' ? 'server' : 'client';
        $check(! in_array('laravel-sso/'.$other, $installedNames, true)
            && ($role !== 'client' || ! in_array('laravel/passport', $installedNames, true)), $role.'_dependency_independence');
        $lock = json_decode(file_get_contents($base.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $locked = array_values(array_filter($lock['packages'], static fn ($package) => $package['name'] === 'laravel-sso/'.$role))[0];
        $check($locked['dist']['reference'] === $source && $locked['dist']['type'] === 'zip'
            && $locked['dist']['url'] === $index['packages']['laravel-sso/'.$role]['0.1.x-dev']['dist']['url'], $role.'_locked_source_reference');
        file_put_contents($base.'/bootstrap/providers.php', '<?php return [];');
        file_put_contents($base.'/bootstrap/app.php', '<?php require dirname(__DIR__)."/vendor/autoload.php"; return \\Illuminate\\Foundation\\Application::configure(basePath: dirname(__DIR__))->withRouting(web: dirname(__DIR__)."/routes/web.php")->withMiddleware(fn ($middleware) => null)->withExceptions(fn ($exceptions) => null)->create();');
        file_put_contents($base.'/routes/web.php', '<?php');
        file_put_contents($base.'/artisan', '<?php $app = require __DIR__."/bootstrap/app.php"; exit($app->handleCommand(new \\Symfony\\Component\\Console\\Input\\ArgvInput));');
        file_put_contents($base.'/.env', "APP_NAME=DistributionCheck\nAPP_ENV=local\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\nAPP_URL=https://distribution.test\nSESSION_DRIVER=file\nCACHE_STORE=file\nSSO_SERVER_ENABLED=false\nSSO_CLIENT_ENABLED=false\n");
        $command([PHP_BINARY, $base.'/artisan', 'package:discover', '--no-interaction'], $base, $role.'-discover');
        $commands = $command([PHP_BINARY, $base.'/artisan', 'list', '--raw'], $base, $role.'-commands');
        $check(str_contains($commands, 'sso:install-'.$role), $role.'_laravel_autodiscovery');
        $command([PHP_BINARY, $base.'/artisan', 'sso:install-'.$role, '--no-interaction'], $base, $role.'-installer');
        $check(is_file($base.'/config/sso-'.$role.'.php') && count(glob($base.'/database/migrations/*sso*.php')) === 1, $role.'_configuration_and_single_migration');
        $check(! is_file($base.'/database/database.sqlite'), $role.'_no_database_or_users_created');
        // Reinstall from composer.lock, fetching archives again and preserving the exact reference.
        $command([PHP_BINARY, $composer, 'reinstall', 'laravel-sso/*', '--no-interaction', '--no-progress', '--no-plugins', '--no-scripts', '--prefer-dist'], $base, $role.'-reinstall');
        $check(is_file($base.'/vendor/laravel-sso/'.$role.'/src/Sso'.ucfirst($role).'ServiceProvider.php'), $role.'_reinstall_from_lock');
    }
    $report['passed'] = true;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $report['owned_server_stopped'] = true;
    file_put_contents($run.'/result.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    echo ($report['passed'] ? 'PASS' : 'FAIL').': '.count($report['checks']).' distribution checks; '.$report['fixture_directory']."/result.json\n";
}
