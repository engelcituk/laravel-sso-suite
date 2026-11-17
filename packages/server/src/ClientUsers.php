<?php

declare(strict_types=1);

namespace LaravelSso\Server;

use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

final class ClientUsers
{
    public function allows(string $client, string $subject, bool $lock = false): bool
    {
        $registration = DB::table('sso_clients')->where('client_id', $client)->first();
        if (! $registration) {
            return false;
        }
        if (! ($registration->restrict_users ?? false)) {
            return true; // Explicit legacy/allow-all policy; new command registrations are restricted.
        }
        $query = DB::table('sso_client_users')->where(['client_id' => $client, 'subject' => $subject]);
        if ($lock) {
            DB::table('sso_clients')->where('client_id', $client)->lockForUpdate()->first();

            return $query->lockForUpdate()->first() !== null;
        }

        return $query->exists();
    }

    public function assign(string $client, string $subject, bool $remove = false): void
    {
        Transactions::run(function () use ($client, $subject, $remove): void {
            $registration = DB::table('sso_clients')->where('client_id', $client)->lockForUpdate()->first();
            if (! $registration || ! Passport::client()->newQuery()->whereKey($client)->where('revoked', false)->exists()
                || strlen($subject) > 255 || ! app(Accounts::class)->model()->newQuery()->find($subject)) {
                throw new \RuntimeException('An active registered application and an existing central user ID are required.');
            }
            if (! ($registration->restrict_users ?? false)) {
                throw new \RuntimeException('This application explicitly allows all users; individual assignments require restricted access.');
            }
            $key = ['client_id' => $client, 'subject' => $subject];
            if (! $remove) {
                if (DB::table('sso_client_users')->insertOrIgnore($key) === 1) {
                    app(Audit::class)->record('client.user_granted', ['client' => $client, 'subject' => $subject]);
                }

                return;
            }
            DB::table('sso_client_users')->where($key)->delete();
            // Current locking read includes tokens committed before this registration lock.
            $bindings = DB::table('sso_token_sessions')->join('sso_sessions', 'sso_sessions.sid', '=', 'sso_token_sessions.sid')
                ->where('sso_token_sessions.client_id', $client)->where('sso_sessions.subject', $subject)
                ->select('sso_token_sessions.token_id')->lockForUpdate()->get();
            Passport::token()->newQuery()->whereIn('id', $bindings->pluck('token_id'))->update(['revoked' => true]);
            app(Audit::class)->record('client.user_revoked', ['client' => $client, 'subject' => $subject]);
        });
    }
}
