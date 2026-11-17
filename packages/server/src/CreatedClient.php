<?php

declare(strict_types=1);

namespace LaravelSso\Server;

/** Credentials available only from the creation result, never reconstructed from storage. */
final readonly class CreatedClient implements \JsonSerializable
{
    public function __construct(public string $id, #[\SensitiveParameter] private ?string $secret) {}

    public function plainSecret(): ?string
    {
        return $this->secret;
    }

    public function jsonSerialize(): array
    {
        return ['id' => $this->id];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    public function __serialize(): array
    {
        return $this->jsonSerialize();
    }

    public function __unserialize(array $data): void
    {
        if (! is_string($data['id'] ?? null)) {
            throw new \UnexpectedValueException('A serialized client result requires an ID.');
        }
        $this->id = $data['id'];
        $this->secret = null;
    }
}
