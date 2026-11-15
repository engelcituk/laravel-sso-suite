<?php

declare(strict_types=1);

namespace LaravelSso\Client\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSsoEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('sso-client.enabled'), 404);

        return $next($request);
    }
}
