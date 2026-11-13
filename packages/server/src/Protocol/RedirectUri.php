<?php

declare(strict_types=1);

namespace LaravelSso\Server\Protocol;

final class RedirectUri
{
    public function safe(string $uri): bool
    {
        $parts = parse_url($uri);
        $schemes = config('sso-server.allow_http') && ! app()->environment('production') ? ['http', 'https'] : ['https'];

        return strlen($uri) <= 2048 && filter_var($uri, FILTER_VALIDATE_URL) !== false && is_array($parts)
            && isset($parts['host']) && in_array($parts['scheme'] ?? '', $schemes, true)
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment'])
            && preg_match('/[\x00-\x20\x7f]/', $uri) === 0;
    }
}
