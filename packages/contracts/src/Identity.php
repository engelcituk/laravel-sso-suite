<?php

declare(strict_types=1);

namespace LaravelSso\Contracts;

/** Claims accepted only after cryptographic and transaction validation. */
final readonly class Identity
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public string $issuer,
        public string $subject,
        public string $sessionId,
        public int $authenticatedAt,
        public array $attributes = [],
    ) {
        if ($issuer === '' || $subject === '' || $sessionId === '') {
            throw new \InvalidArgumentException('Identity identifiers cannot be empty.');
        }
    }
}
