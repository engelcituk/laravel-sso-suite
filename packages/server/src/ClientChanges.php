<?php

declare(strict_types=1);

namespace LaravelSso\Server;

/** Null preserves a field; an empty optional logout URL clears it. Client type/secret are immutable. */
final readonly class ClientChanges
{
    public function __construct(
        public ?string $name = null,
        public ?string $redirectUri = null,
        public ?bool $firstParty = null,
        public ?bool $requirePkce = null,
        public ?bool $restrictUsers = null,
        public ?string $backchannelLogoutUri = null,
        public ?string $postLogoutRedirectUri = null,
    ) {}
}
