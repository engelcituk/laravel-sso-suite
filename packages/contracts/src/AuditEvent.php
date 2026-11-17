<?php

declare(strict_types=1);

namespace LaravelSso\Contracts;

/** Versioned, deliberately small payload: never pass models, claims or HTTP input here. */
final readonly class AuditEvent implements \JsonSerializable
{
    public const NAMES = [
        'server' => ['auth.failed', 'auth.login', 'auth.password_reset', 'auth.registered', 'client.user_granted', 'client.user_revoked', 'device.registered', 'device.revoked', 'device.renamed',
            'password.confirmed', 'password.rejected', 'password.required', 'remember.restored', 'remember.replayed', 'remember.expired',
            'session.created', 'session.revoked', 'code.issued', 'authorization.rejected', 'consent.approved', 'consent.denied',
            'token.issued', 'token.rejected', 'token.replayed', 'logout.delivered', 'logout.retry', 'client.registered', 'client.updated', 'client.revoked', 'keys.created'],
        'client' => ['identity.linked', 'identity.provisioned', 'identity.rejected', 'login.completed', 'login.rejected', 'logout.local',
            'logout.received', 'logout.duplicate', 'logout.rejected', 'session.denied'],
    ];

    /** @param array<string, string> $references
     * @param  array<string, bool|int>  $context
     */
    private function __construct(
        public string $id,
        public string $component,
        public string $name,
        public int $occurredAt,
        public array $references,
        public array $context,
    ) {}

    /** @param array<string, mixed> $references Raw identifiers validated and HMACed before dispatch.
     * @param  array<string, mixed>  $context  Untrusted payload boundary; rejected unless explicitly allowed.
     */
    public static function make(string $component, string $name, string $key, array $references = [], array $context = []): self
    {
        if (! in_array($name, self::NAMES[$component] ?? [], true) || $key === '') {
            throw new \InvalidArgumentException('Invalid audit event envelope.');
        }
        $safe = [];
        foreach ($references as $type => $value) {
            if (! in_array($type, ['issuer', 'subject', 'client', 'device', 'sid', 'delivery'], true) || ! is_string($value)) {
                throw new \InvalidArgumentException('Unsupported audit reference.');
            }
            $safe[$type] = hash_hmac('sha256', 'sso-audit-v1|'.$component.'|'.$type.'|'.$value, $key);
        }
        foreach ($context as $type => $value) {
            if (! in_array($type, ['remembered', 'attempt', 'count', 'created'], true) || (! is_int($value) && ! is_bool($value))) {
                throw new \InvalidArgumentException('Unsupported audit context.');
            }
        }

        return new self(bin2hex(random_bytes(16)), $component, $name, time(), $safe, $context);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['version' => 1, 'id' => $this->id, 'component' => $this->component, 'name' => $this->name,
            'occurred_at' => $this->occurredAt, 'references' => $this->references, 'context' => $this->context];
    }
}
