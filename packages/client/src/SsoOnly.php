<?php

declare(strict_types=1);

namespace LaravelSso\Client;

/** Optional credential-free model behavior for SSO-only apps. Never alters database columns. */
trait SsoOnly
{
    public function getAuthPassword(): string
    {
        return '';
    }

    public function setPasswordAttribute(mixed $value): never
    {
        throw new \LogicException('SSO identities cannot have a local password.');
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}
}
