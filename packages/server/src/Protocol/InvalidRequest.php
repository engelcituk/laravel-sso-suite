<?php

declare(strict_types=1);

namespace LaravelSso\Server\Protocol;

use Illuminate\Http\JsonResponse;

final class InvalidRequest extends \RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['error' => 'invalid_request'], 400)->header('Cache-Control', 'no-store');
    }

    public function report(): bool
    {
        return true;
    }
}
