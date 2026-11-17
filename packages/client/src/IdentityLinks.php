<?php

declare(strict_types=1);

namespace LaravelSso\Client;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LaravelSso\Client\Exceptions\ProtocolException;
use LaravelSso\Contracts\Identity;

final class IdentityLinks
{
    private function model(): Model&Authenticatable
    {
        $class = config('sso-client.user_model');
        $model = new $class;
        if (! $model instanceof Model || ! $model instanceof Authenticatable
            || $model->getConnection()->getName() !== DB::connection()->getName()) {
            throw new ProtocolException('The identity model must use the client database connection.');
        }

        return $model;
    }

    private function key(string $issuer, string $subject): array
    {
        if ($issuer === '' || $subject === '' || strlen($issuer) > 255 || strlen($subject) > 255) {
            throw new ProtocolException('Identity identifiers are empty or too long.');
        }

        return ['issuer' => $issuer, 'subject' => $subject];
    }

    public function matches(Model&Authenticatable $user, string $issuer, string $subject): bool
    {
        $model = $this->model();
        if ($user::class !== $model::class) {
            return false;
        }

        return DB::table('sso_identities')->where($this->key($issuer, $subject))
            ->where('user_type', $user::class)->where('user_id', (string) $user->getAuthIdentifier())->exists();
    }

    public function find(string $issuer, string $subject): (Model&Authenticatable)|null
    {
        $model = $this->model();
        $row = DB::table('sso_identities')->where($this->key($issuer, $subject))->first();
        if (! $row || $row->user_type !== $model::class || $row->user_id === null) {
            return null;
        }

        return $model->newQuery()->find($row->user_id);
    }

    /** Explicit operator action. An established identity cannot silently change owners. */
    public function link(Model&Authenticatable $user, string $issuer, string $subject): void
    {
        $model = $this->model();
        if (! $user instanceof $model || $user::class !== $model::class || ! $user->exists || $user->getAuthIdentifier() === null
            || $user->getConnection()->getName() !== DB::connection()->getName()
            || strlen((string) $user->getAuthIdentifier()) > 191) {
            throw new ProtocolException('An existing configured local user is required.');
        }
        Transactions::run(function () use ($user, $issuer, $subject): void {
            $key = $this->key($issuer, $subject);
            $inserted = DB::table('sso_identities')->insertOrIgnore($key + ['user_type' => $user::class, 'user_id' => (string) $user->getAuthIdentifier()]);
            $row = DB::table('sso_identities')->where($key)->lockForUpdate()->first();
            if (! $row) {
                throw new DeadlockException('Identity mapping requires a fresh snapshot.', 40001);
            }
            if ($row->user_type !== $user::class || $row->user_id !== (string) $user->getAuthIdentifier()) {
                throw new ProtocolException('Identity already belongs to another local user.');
            }
            if ($inserted === 1) {
                app(Audit::class)->record('identity.linked', ['issuer' => $issuer, 'subject' => $subject]);
            }
        });
    }

    /** The selected factory creates a new owner atomically; established owners are never updated. */
    public function resolve(Identity $identity, ?callable $factory): Model&Authenticatable
    {
        return Transactions::run(function () use ($identity, $factory): Model&Authenticatable {
            $key = $this->key($identity->issuer, $identity->subject);
            $model = $this->model();
            $row = DB::table('sso_identities')->where($key)->lockForUpdate()->first();
            if (! $row && $factory !== null) {
                DB::table('sso_identities')->insertOrIgnore($key + ['user_type' => $model::class, 'user_id' => null]);
                $row = DB::table('sso_identities')->where($key)->lockForUpdate()->first();
                if (! $row) {
                    throw new DeadlockException('Identity mapping requires a fresh snapshot.', 40001);
                }
            }
            if (! $row || $row->user_type !== $model::class) {
                throw new ProtocolException('Identity has not been linked to a local user.');
            }
            if ($row->user_id === null) {
                if ($factory === null) {
                    throw new ProtocolException('Identity provisioning is incomplete.');
                }
                $user = $factory($identity);
                if (! $user instanceof $model || ! $user->exists
                    || $user->getAuthIdentifier() === null || strlen((string) $user->getAuthIdentifier()) > 191
                    || $user->getConnection()->getName() !== DB::connection()->getName()) {
                    throw new ProtocolException('The host factory must return a persisted configured user.');
                }
                DB::table('sso_identities')->where('id', $row->id)->update(['user_id' => (string) $user->getAuthIdentifier()]);

                return $user;
            }
            // A MySQL snapshot may predate the user committed with this mapping.
            // Read current state without writing any host attributes.
            $user = $model->newQuery()->whereKey($row->user_id)->lockForUpdate()->first();
            if (! $user) {
                throw new ProtocolException('The linked local user no longer exists.');
            }

            return $user;
        });
    }
}
