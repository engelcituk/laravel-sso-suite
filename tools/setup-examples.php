<?php

declare(strict_types=1);

// Reproducible development setup. Does not reset databases or overwrite existing secrets.
$root = dirname(__DIR__);
function artisan(string $base, array $args): void
{
    $process = proc_open([PHP_BINARY, $base.'/artisan', ...$args], [STDIN, STDOUT, STDERR], $pipes, $base);
    if (! is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Example setup failed: '.implode(' ', $args));
    }
}
foreach (['identity-server', 'app-one', 'app-two'] as $name) {
    $base = $root.'/examples/'.$name;
    foreach (['bootstrap/cache', 'database', 'storage/framework/sessions', 'storage/framework/views', 'storage/framework/cache/data', 'storage/logs'] as $path) {
        if (! is_dir($base.'/'.$path)) {
            mkdir($base.'/'.$path, 0700, true);
        }
    }
    if (! file_exists($base.'/.env')) {
        $env = file_get_contents($base.'/.env.example');
        $env = str_replace("APP_KEY=\n", 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n", str_replace("\r\n", "\n", $env));
        file_put_contents($base.'/.env', $env);
    }
    if (! file_exists($base.'/database/database.sqlite')) {
        touch($base.'/database/database.sqlite');
    }
    $sources = $name === 'identity-server' ? [$root.'/vendor/laravel/passport/database/migrations', $root.'/packages/server/database/migrations']
        : [$root.'/packages/client/database/migrations'];
    foreach ($sources as $source) {
        foreach (glob($source.'/*.php') as $migration) {
            // Laravel's publisher changes timestamps on every run; preserve one copy by suffix.
            $suffix = substr(basename($migration), 18);
            if (glob($base.'/database/migrations/*'.$suffix) === []) {
                copy($migration, $base.'/database/migrations/'.basename($migration));
            }
        }
    }
    artisan($base, ['migrate', '--force']);
    if ($name === 'identity-server') {
        if (! file_exists($base.'/storage/oauth-private.key')) {
            artisan($base, ['passport:keys']);
        }
        if (! file_exists($base.'/storage/sso/private.pem')) {
            artisan($base, ['sso:keys']);
        }
    }
}
artisan($root.'/examples/identity-server', ['example:seed']);
echo "Examples ready. Start the three servers using the commands in README.md.\n";
