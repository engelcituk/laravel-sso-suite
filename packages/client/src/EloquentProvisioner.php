<?php

declare(strict_types=1);

namespace LaravelSso\Client;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use LaravelSso\Client\Contracts\ProvisionsUsers;
use LaravelSso\Client\Events\IdentityProvisioned;
use LaravelSso\Client\Exceptions\ProtocolException;
use LaravelSso\Contracts\Identity;

final class EloquentProvisioner implements ProvisionsUsers
{
    public function provision(Identity $identity): Authenticatable
    {
        /** @var class-string<Model&Authenticatable> $model */
        $model = config('sso-client.user_model');
        $key = ['sso_issuer' => $identity->issuer, 'sso_subject' => $identity->subject];
        $attributes = array_filter(array_intersect_key($identity->attributes, array_flip(['name', 'email'])),
            static fn ($value) => is_string($value));
        $user = $model::query()->where($key)->first();
        if (! $user) {
            if (config('sso-client.provision') !== 'automatic') {
                app(Audit::class)->record('identity.rejected', ['issuer' => $identity->issuer, 'subject' => $identity->subject], [], (new $model)->getConnection());
                throw new ProtocolException('Identity has not been approved by this application.');
            }
            // createOrFirst retries the unique-key conflict using a savepoint. No email linking.
            try {
                $user = $model::query()->createOrFirst($key, $attributes);
            } catch (UniqueConstraintViolationException $error) {
                // MySQL REPEATABLE READ may hide the winner from createOrFirst's
                // fallback SELECT inside an existing transaction. Read current state.
                $user = $model::query()->where($key)->lockForUpdate()->first();
                if (! $user) {
                    $connection = (new $model)->getConnection();
                    if ($connection->getDriverName() === 'pgsql' && $connection->transactionLevel() > 0) {
                        throw new DeadlockException('Concurrent identity requires a fresh transaction snapshot.', 40001, $error);
                    }
                    throw $error;
                }
            }
        }
        $allowed = config('sso-client.allowed');
        if ($allowed !== null && ! $allowed($user, $identity)) {
            app(Audit::class)->record('identity.rejected', ['issuer' => $identity->issuer, 'subject' => $identity->subject], [], $user->getConnection());
            throw new ProtocolException('Local access policy rejected this identity.');
        }
        $user->forceFill($attributes)->save();
        // The caller may own a transaction and retry it after a database deadlock.
        // Publish the event only for the committed attempt.
        $user->getConnection()->afterCommit(fn () => event(new IdentityProvisioned($user, $identity)));
        app(Audit::class)->record('identity.provisioned', ['issuer' => $identity->issuer, 'subject' => $identity->subject],
            ['created' => $user->wasRecentlyCreated], $user->getConnection());

        return $user;
    }
}
