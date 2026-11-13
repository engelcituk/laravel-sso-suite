<?php

declare(strict_types=1);

namespace LaravelSso\Server\Protocol;

use Illuminate\Http\JsonResponse;

final class BearerFailure extends \RuntimeException
{
    public function __construct(private string $error = 'invalid_token', private int $status = 401) {}

    public function render(): JsonResponse
    {
        return response()->json(['error' => $this->error], $this->status)->header('Cache-Control', 'no-store')
            ->header('WWW-Authenticate', 'Bearer error="'.$this->error.'"');
    }

    public function report(): bool
    {
        return true;
    }
}
