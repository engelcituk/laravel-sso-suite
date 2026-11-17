<?php

declare(strict_types=1);

namespace LaravelSso\Server;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

/** @internal Must be acquired inside the encompassing transaction, before OAuth rows. */
final class ClientLocks
{
    public static function acquire(string $id): \stdClass
    {
        $row = Str::isUuid($id) ? DB::table('sso_clients')->where('client_id', $id)->lockForUpdate()->first() : null;
        if (! $row) {
            throw (new ModelNotFoundException)->setModel(Passport::clientModel(), [$id]);
        }
        // A real tuple version makes PostgreSQL REPEATABLE READ retry old snapshots.
        DB::table('sso_clients')->where('client_id', $id)->update(['require_pkce' => $row->require_pkce]);

        return $row;
    }
}
