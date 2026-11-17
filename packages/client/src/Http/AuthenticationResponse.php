<?php

declare(strict_types=1);

namespace LaravelSso\Client\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticationResponse
{
    public static function redirect(Request $request, string $url): Response
    {
        if ($request->hasHeader('X-Inertia')) {
            return response('', 409)->header('X-Inertia-Location', $url)
                ->header('Vary', 'X-Inertia')->header('Cache-Control', 'no-store');
        }

        return redirect($url);
    }
}
