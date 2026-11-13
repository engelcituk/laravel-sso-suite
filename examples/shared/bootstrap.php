<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return static function (string $base): Application {
    $autoload = is_file($base.'/vendor/autoload.php') ? $base.'/vendor/autoload.php' : dirname(__DIR__, 2).'/vendor/autoload.php';
    require_once $autoload;
    require_once __DIR__.(basename($base) === 'identity-server' ? '/central-model.php' : '/client-model.php');
    $app = Application::configure(basePath: $base)
        ->withRouting(web: $base.'/routes/web.php', commands: $base.'/routes/console.php')
        ->withMiddleware(function (Middleware $middleware): void {
            $middleware->redirectGuestsTo('/sso/login');
        })
        ->withExceptions(function (Exceptions $exceptions): void {})
        ->create();
    $app->useConfigPath(__DIR__.'/config');

    return $app;
};
