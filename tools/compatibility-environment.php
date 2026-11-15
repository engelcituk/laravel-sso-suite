<?php

declare(strict_types=1);

use Composer\InstalledVersions;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$profile = getenv('SSO_DEPENDENCIES') ?: 'locked';
if (! in_array($profile, ['locked', 'lowest', 'latest'], true)) {
    throw new RuntimeException('Unknown dependency profile.');
}
$directory = $root.'/.runtime/compatibility';
if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
    throw new RuntimeException('Cannot create compatibility evidence directory.');
}
$inventory = [];
foreach (InstalledVersions::getInstalledPackages() as $package) {
    $inventory[$package] = InstalledVersions::getPrettyVersion($package);
}
ksort($inventory);
file_put_contents($directory.'/installed.json', json_encode($inventory, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
copy($root.'/composer.lock', $directory.'/composer.lock');
$report = [
    'checked_at_utc' => gmdate(DATE_ATOM), 'profile' => $profile,
    'php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'commit' => getenv('GITHUB_SHA') ?: null,
    'platform_php' => json_decode(file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR)['config']['platform']['php'],
    'lock_sha256' => hash_file('sha256', $root.'/composer.lock'),
    'installed_sha256' => hash_file('sha256', $directory.'/installed.json'),
    'versions' => array_intersect_key($inventory, array_flip([
        'laravel/framework', 'laravel/passport', 'league/oauth2-server', 'firebase/php-jwt',
        'orchestra/testbench', 'phpunit/phpunit', 'laravel/pint', 'larastan/larastan', 'phpstan/phpstan',
    ])),
];
file_put_contents($directory.'/environment.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
echo 'COMPATIBILITY_ENVIRONMENT='.json_encode($report, JSON_THROW_ON_ERROR)."\n";
