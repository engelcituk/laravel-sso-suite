<?php

declare(strict_types=1);

namespace LaravelSso\Server;

/** Explicit application choices; never construct policy flags from unvalidated public input. */
final readonly class ClientDefinition
{
    public function __construct(
        public string $name,
        public string $redirectUri,
        public bool $public = false,
        public bool $firstParty = false,
        public bool $requirePkce = true,
        public bool $restrictUsers = true,
        public ?string $backchannelLogoutUri = null,
        public ?string $postLogoutRedirectUri = null,
    ) {}
}
