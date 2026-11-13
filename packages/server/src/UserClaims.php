<?php

declare(strict_types=1);

namespace LaravelSso\Server;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

final class UserClaims
{
    public const PROFILE = ['name', 'given_name', 'family_name', 'middle_name', 'nickname', 'preferred_username',
        'profile', 'picture', 'website', 'gender', 'birthdate', 'zoneinfo', 'locale', 'updated_at'];

    /** @return list<string> */
    public function supported(): array
    {
        return array_values(array_filter(array_merge(self::PROFILE, ['email', 'email_verified']),
            fn (string $name): bool => is_string(config('sso-server.claim_attributes.'.$name))
                && config('sso-server.claim_attributes.'.$name) !== ''));
    }

    /** @return array<string, string|int|bool> */
    public function available(Model $user): array
    {
        $claims = [];
        foreach ($this->supported() as $name) {
            $attribute = config('sso-server.claim_attributes.'.$name);
            if (! is_string($attribute) || $attribute === '') {
                continue;
            }
            [$rootAttribute] = explode('.', $attribute, 2);
            if (! array_key_exists($rootAttribute, $user->getAttributes())) {
                continue;
            }
            $value = data_get([$rootAttribute => $user->getAttribute($rootAttribute)], $attribute);
            if ($name === 'email_verified') {
                $value = $value === true || $value instanceof DateTimeInterface
                    || (is_int($value) && $value > 0)
                    || (is_string($value) && $value !== '' && strtotime($value) !== false);
            } elseif ($name === 'updated_at') {
                $value = $value instanceof DateTimeInterface ? $value->getTimestamp() : $value;
                if (! is_int($value) || $value < 0) {
                    continue;
                }
            } elseif (! is_string($value) || $value === '') {
                continue;
            }
            $claims[$name] = $value;
        }
        if (! isset($claims['email'])) {
            unset($claims['email_verified']);
        }

        return $claims;
    }

    /** @param list<string> $scopes
     * @return array<string, string|int|bool>
     */
    public function scoped(Model $user, array $scopes): array
    {
        $allowed = [];
        if (in_array('profile', $scopes, true)) {
            $allowed = self::PROFILE;
        }
        if (in_array('email', $scopes, true)) {
            $allowed = array_merge($allowed, ['email', 'email_verified']);
        }

        return array_intersect_key($this->available($user), array_flip($allowed));
    }
}
