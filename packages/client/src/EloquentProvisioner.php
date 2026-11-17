<?php

declare(strict_types=1);

namespace LaravelSso\Client;

use Illuminate\Contracts\Auth\Authenticatable;
use LaravelSso\Client\Contracts\ProvisionsUsers;
use LaravelSso\Client\Events\IdentityProvisioned;
use LaravelSso\Client\Exceptions\ProtocolException;
use LaravelSso\Contracts\Identity;

final class EloquentProvisioner implements ProvisionsUsers
{
    public function provision(Identity $identity): Authenticatable
    {
        if (! config('sso-client.enabled')) {
            throw new ProtocolException('Central authentication is disabled.');
        }
        $mode = config('sso-client.provision');
        if (! in_array($mode, ['greenfield', 'restricted', 'automatic'], true)) {
            throw new ProtocolException('Select greenfield, restricted or automatic provisioning.');
        }
        $factory = $mode === 'greenfield' ? app(GreenfieldUserFactory::class)
            : ($mode === 'automatic' ? config('sso-client.create_user') : null);
        if (is_string($factory) && class_exists($factory)) {
            $factory = app($factory);
        }
        if ($mode === 'automatic' && ! is_callable($factory)) {
            throw new ProtocolException('Automatic provisioning requires a host-owned user factory.');
        }
        try {
            return Transactions::run(function () use ($identity, $factory): Authenticatable {
                $user = app(IdentityLinks::class)->resolve($identity, $factory);
                $allowed = config('sso-client.allowed');
                if ($allowed !== null && ! $allowed($user, $identity)) {
                    throw new ProtocolException('Local access policy rejected this identity.');
                }
                $user->getConnection()->afterCommit(fn () => event(new IdentityProvisioned($user, $identity)));
                app(Audit::class)->record('identity.provisioned', ['issuer' => $identity->issuer, 'subject' => $identity->subject],
                    ['created' => $user->wasRecentlyCreated], $user->getConnection());

                return $user;
            });
        } catch (ProtocolException $error) {
            app(Audit::class)->record('identity.rejected', ['issuer' => $identity->issuer, 'subject' => $identity->subject]);
            throw $error;
        }
    }
}
