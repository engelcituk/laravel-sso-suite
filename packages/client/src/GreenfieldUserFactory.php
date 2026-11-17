<?php

declare(strict_types=1);

namespace LaravelSso\Client;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LaravelSso\Client\Exceptions\ProtocolException;
use LaravelSso\Contracts\Identity;

/** Creates only new conventional Laravel accounts; ownership is always issuer + subject. */
final class GreenfieldUserFactory
{
    public function model(): Model&Authenticatable
    {
        $class = config('sso-client.user_model');
        $model = new $class;
        if (! $model instanceof Model || ! $model instanceof Authenticatable
            || $model->getConnection()->getName() !== DB::connection()->getName()) {
            throw new ProtocolException('Greenfield provisioning requires the configured Eloquent user on the client connection.');
        }
        $schema = $model->getConnection()->getSchemaBuilder();
        if (! $schema->hasColumns($model->getTable(), ['name', 'email', $model->getAuthPasswordName()])
            || ! $schema->hasIndex($model->getTable(), ['email'], 'unique')) {
            throw new ProtocolException('Greenfield provisioning requires name, unique email and a password column; use a custom factory for another schema.');
        }

        return $model;
    }

    public function __invoke(Identity $identity): Model&Authenticatable
    {
        $email = $identity->attributes['email'] ?? null;
        if (! is_string($email) || strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ProtocolException('Greenfield provisioning requires a valid email claim from the trusted identity server.');
        }
        $email = strtolower($email);
        $name = $identity->attributes['name'] ?? $email;
        if (! is_string($name) || ! mb_check_encoding($name, 'UTF-8') || trim($name) === '' || mb_strlen($name) > 255) {
            throw new ProtocolException('Greenfield provisioning requires a valid name claim when supplied.');
        }
        $user = $this->model();
        // Include soft-deleted/scoped accounts. An email match never grants ownership.
        if ($user->newQueryWithoutScopes()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw new ProtocolException('A local account already uses this email; an operator must verify and link the identity.');
        }
        $attributes = ['name' => trim($name), 'email' => $email,
            // The standard Laravel column is NOT NULL. No usable password is issued or copied.
            $user->getAuthPasswordName() => Hash::make(bin2hex(random_bytes(32)))];
        if ($user->getConnection()->getSchemaBuilder()->hasColumn($user->getTable(), 'email_verified_at')) {
            $attributes['email_verified_at'] = ($identity->attributes['email_verified'] ?? false) === true ? now() : null;
        }
        try {
            $user->forceFill($attributes)->save();
        } catch (UniqueConstraintViolationException) {
            // A competing identity may have claimed this email after the check. Never adopt it.
            throw new ProtocolException('A local account identifier is already in use; automatic linking is not allowed.');
        }

        return $user;
    }
}
