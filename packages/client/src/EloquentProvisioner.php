<?php

declare(strict_types=1);

namespace LaravelSso\Client;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
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
                throw new ProtocolException('Identity has not been approved by this application.');
            }
            // createOrFirst retries the unique-key conflict using a savepoint. No email linking.
            $user = $model::query()->createOrFirst($key, $attributes);
        }
        $allowed = config('sso-client.allowed');
        if ($allowed !== null && ! $allowed($user, $identity)) {
            throw new ProtocolException('Local access policy rejected this identity.');
        }
        $user->forceFill($attributes)->save();
        event(new IdentityProvisioned($user, $identity));

        return $user;
    }
}
