<?php

declare(strict_types=1);

namespace LaravelSso\Client\Exceptions;

use Illuminate\Http\JsonResponse;

final class ProtocolException extends \RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['error' => 'invalid_sso_transaction'], 400)->header('Cache-Control', 'no-store');
    }

    public function report(): bool
    {
        return true; // Do not put tokens or response bodies from chained exceptions in logs.
    }
}
