<?php

declare(strict_types=1);

namespace LaravelSso\Server;

use Illuminate\Support\Facades\Route;

final class Login
{
    public function usesPackageAuthentication(): bool
    {
        $mode = config('sso-server.authentication', 'auto');
        if (! in_array($mode, ['auto', 'host', 'package'], true)) {
            throw new \RuntimeException('Select auto, host or package authentication.');
        }

        return $mode === 'package' || ($mode === 'auto' && ! config('sso-server.login_url')
            && ! Route::has(config('sso-server.login_route', 'login')));
    }

    public function url(): string
    {
        $url = config('sso-server.login_url');
        if (is_string($url) && $url !== '') {
            if (str_starts_with($url, '/') && ! str_starts_with($url, '//') && ! str_contains($url, '\\') && ! preg_match('/[\x00-\x20]/', $url)) {
                return $url;
            }
            $parts = parse_url($url);
            if (! is_array($parts) || ! filter_var($url, FILTER_VALIDATE_URL) || isset($parts['user']) || isset($parts['pass'])
                || ! in_array($parts['scheme'] ?? '', config('sso-server.allow_http') && ! app()->environment('production') ? ['https', 'http'] : ['https'], true)) {
                throw new \RuntimeException('Configure a trusted login URL; HTTPS is required outside local development.');
            }

            return $url;
        }
        $route = config('sso-server.login_route', 'login');

        if (config('sso-server.authentication') === 'package') {
            return route('sso-server.auth.login');
        }
        if (config('sso-server.authentication') === 'host' && (! is_string($route) || ! Route::has($route))) {
            throw new \RuntimeException('Host authentication requires an existing login route or a configured login URL.');
        }

        return is_string($route) && Route::has($route) ? route($route) : route('sso-server.auth.login');
    }

    public function destination(mixed $intended): string
    {
        if (is_string($intended) && ! str_contains($intended, '\\') && ! preg_match('/[\x00-\x20]/', $intended)) {
            if (str_starts_with($intended, '/') && ! str_starts_with($intended, '//')) {
                return $intended;
            }
            $origin = config('sso-server.issuer');
            if (is_string($origin) && str_starts_with($intended, $origin.'/')) {
                return $intended;
            }
        }

        return '/';
    }
}
