<?php

declare(strict_types=1);

namespace LaravelSso\Client;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LaravelSso\Contracts\AuditEvent;

final class Audit
{
    /** @param array<string, string> $references
     * @param  array<string, bool|int>  $context
     */
    public function record(string $name, array $references = [], array $context = [], ?Connection $connection = null): void
    {
        $event = AuditEvent::make('client', $name, app('encrypter')->getKey(), $references, $context);
        ($connection ?? DB::connection())->afterCommit(function () use ($event): void {
            if (config('sso-client.audit_log', false)) {
                Log::channel(config('sso-client.audit_channel'))->info('sso.audit', $event->jsonSerialize());
            }
            event($event);
        });
    }
}
