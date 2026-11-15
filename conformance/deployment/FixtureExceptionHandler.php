<?php

namespace LaravelSso\Conformance;

use Illuminate\Foundation\Exceptions\Handler;
use LaravelSso\Client\Exceptions\ProtocolException;

/** Fixture-only diagnostics; only the fixed top-level protocol reason is retained. */
final class FixtureExceptionHandler extends Handler
{
    public function report(\Throwable $error): void
    {
        if ($error instanceof ProtocolException) {
            file_put_contents(storage_path('logs/protocol-reasons.log'), $error->getMessage()."\n", FILE_APPEND);
        }
        parent::report($error);
    }
}
