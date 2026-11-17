<?php

declare(strict_types=1);

namespace LaravelSso\Server;

/** Safe read model: never contains a client secret, hash, user or token. */
final readonly class ClientDetails implements \JsonSerializable
{
    /** @param list<string> $redirectUris
     * @param  list<string>  $postLogoutRedirectUris
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $redirectUris,
        public bool $public,
        public bool $firstParty,
        public bool $requirePkce,
        public bool $restrictUsers,
        public ?string $backchannelLogoutUri,
        public array $postLogoutRedirectUris,
        public bool $revoked,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'redirect_uris' => $this->redirectUris,
            'public' => $this->public, 'first_party' => $this->firstParty, 'require_pkce' => $this->requirePkce,
            'restrict_users' => $this->restrictUsers, 'backchannel_logout_uri' => $this->backchannelLogoutUri,
            'post_logout_redirect_uris' => $this->postLogoutRedirectUris, 'revoked' => $this->revoked];
    }
}
