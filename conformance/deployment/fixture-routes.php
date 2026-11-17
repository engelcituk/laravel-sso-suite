<?php

// Loaded only by disposable laboratory apps, never by package or example routes.
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/lab/health', function (Request $request) {
    return response()->json(['php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'secure' => $request->isSecure(),
        'host' => $request->getHost(), 'configuration_cached' => app()->configurationIsCached(), 'routes_cached' => app()->routesAreCached(),
        'session_driver' => config('session.driver'), 'opcache' => (bool) ini_get('opcache.enable'),
        'sso_enabled' => (bool) config('sso-client.enabled'),
        'driver' => DB::getDriverName(), 'database_version' => DB::selectOne('select version() as version')->version]);
});
Route::get('/lab/cookie-probe', fn (Request $request) => response()->json([
    'authenticated' => Auth::check(), 'fetch_site' => $request->header('Sec-Fetch-Site'),
])->header('Cache-Control', 'no-store'));
Route::get('/lab/frame', fn () => response('<!doctype html><iframe title="Cookie probe" src="'.htmlspecialchars(config('sso-client.issuer').'/lab/cookie-probe', ENT_QUOTES).'"></iframe>'));
Route::get('/lab/attack', fn () => response('<!doctype html><title>Local cross-site negative case</title>'));
