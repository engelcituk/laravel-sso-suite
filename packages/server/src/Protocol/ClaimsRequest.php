<?php

declare(strict_types=1);

namespace LaravelSso\Server\Protocol;

use LaravelSso\Server\UserClaims;

/** Normal claims only. Selection never overrides identity or protected JWT claims. */
final class ClaimsRequest
{
    public const ACR = '0'; // No identity-assurance level is asserted by this password login.

    /** @return array<string, array<string, array<string, mixed>>> */
    public function parse(?string $json): array
    {
        if ($json === null) {
            return [];
        }
        if (strlen($json) > 4096) {
            throw new InvalidRequest;
        }
        try {
            $request = json_decode($json, false, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidRequest;
        }
        if (! $request instanceof \stdClass) {
            throw new InvalidRequest;
        }
        $result = [];
        foreach (['userinfo', 'id_token'] as $target) {
            $claims = $request->$target ?? null;
            if ($claims === null) {
                continue;
            }
            if (! $claims instanceof \stdClass || count(get_object_vars($claims)) > 32) {
                throw new InvalidRequest;
            }
            foreach (get_object_vars($claims) as $name => $definition) {
                if (! in_array($name, array_merge(UserClaims::PROFILE, ['email', 'email_verified', 'sub', 'acr', 'auth_time']), true)) {
                    continue; // Unknown claim types and their extensions are not interpreted.
                }
                if ($definition !== null && ! $definition instanceof \stdClass) {
                    throw new InvalidRequest;
                }
                $definition = (array) ($definition ?? new \stdClass);
                if ((array_key_exists('essential', $definition) && ! is_bool($definition['essential']))
                    || (array_key_exists('value', $definition) && array_key_exists('values', $definition))) {
                    throw new InvalidRequest;
                }
                foreach (['value', 'values'] as $key) {
                    if (! array_key_exists($key, $definition)) {
                        continue;
                    }
                    $values = $key === 'value' ? [$definition[$key]] : $definition[$key];
                    if (! is_array($values) || ! array_is_list($values) || count($values) > 16 || $values === []) {
                        throw new InvalidRequest;
                    }
                    foreach ($values as $value) {
                        $valid = match ($name) {
                            'updated_at', 'auth_time' => is_int($value) && $value >= 0,
                            'email_verified' => is_bool($value),
                            default => is_string($value) && strlen($value) <= 1024,
                        };
                        if (! $valid) {
                            throw new InvalidRequest;
                        }
                    }
                }
                $result[$target][$name] = array_intersect_key($definition, array_flip(['essential', 'value', 'values']));
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $definition */
    public function matches(array $definition, mixed $value): bool
    {
        return (! array_key_exists('value', $definition) || $value === $definition['value'])
            && (! array_key_exists('values', $definition) || in_array($value, $definition['values'], true));
    }

    /** @param array<string, array<string, array<string, mixed>>> $request */
    public function permits(array $request, string $subject): bool
    {
        foreach (['userinfo', 'id_token'] as $target) {
            if (isset($request[$target]['sub']) && ! $this->matches($request[$target]['sub'], $subject)) {
                return false;
            }
        }
        $acr = $request['id_token']['acr'] ?? [];

        return ! ($acr['essential'] ?? false) || $this->matches($acr, self::ACR);
    }

    /** @param array<string, array<string, array<string, mixed>>> $request
     * @param  array<string, mixed>  $available
     * @return array<string, mixed>
     */
    public function select(array $request, string $target, array $available): array
    {
        $selected = [];
        foreach ($request[$target] ?? [] as $name => $definition) {
            if (array_key_exists($name, $available) && $this->matches($definition, $available[$name])) {
                $selected[$name] = $available[$name];
            }
        }

        return $selected;
    }

    /** @param array<string, array<string, array<string, mixed>>> $request
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     */
    public function constrain(array $request, string $target, array $claims): array
    {
        foreach ($request[$target] ?? [] as $name => $definition) {
            if (in_array($name, ['sub', 'acr'], true)) {
                continue; // Identity is checked before issuing; acr returns the actual class.
            }
            if (array_key_exists($name, $claims) && ! $this->matches($definition, $claims[$name])) {
                unset($claims[$name]);
            }
        }

        return $claims;
    }
}
