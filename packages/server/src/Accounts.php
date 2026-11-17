<?php

declare(strict_types=1);

namespace LaravelSso\Server;

use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class Accounts
{
    public function model(): Model&Authenticatable
    {
        $provider = config('auth.guards.'.config('sso-server.guard').'.provider');
        $class = config('auth.providers.'.$provider.'.model');
        $model = new $class;
        if (! $model instanceof Model || ! $model instanceof Authenticatable
            || $model->getConnection()->getName() !== DB::connection()->getName()) {
            throw new \RuntimeException('Central accounts require the Eloquent model of the server guard on the default connection.');
        }

        return $model;
    }

    public function checkSchema(): void
    {
        $model = $this->model();
        $schema = $model->getConnection()->getSchemaBuilder();
        if (! $schema->hasColumns($model->getTable(), ['name', 'email', $model->getAuthPasswordName()])
            || ! $schema->hasIndex($model->getTable(), ['email'], 'unique')) {
            throw new \RuntimeException('Central registration requires the conventional name, unique email and password columns.');
        }
    }

    public function passwordRules(bool $confirmed = true): array
    {
        return array_merge(['required', 'string', 'min:'.max(12, (int) config('sso-server.password_min_length', 12)),
            static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('The password must not exceed 72 bytes.');
                }
            }], $confirmed ? ['confirmed'] : []);
    }

    /** Only inserts a new central account. Existing accounts are never adopted or updated. */
    public function create(#[\SensitiveParameter] array $input): Model&Authenticatable
    {
        $data = Validator::make($input, ['name' => 'required|string|max:255', 'email' => 'required|string|email|max:255',
            'password' => $this->passwordRules()])->validate();
        $email = strtolower($data['email']);
        $this->checkSchema();

        return Transactions::run(function () use ($data, $email): Model&Authenticatable {
            $user = $this->model();
            if ($user->newQueryWithoutScopes()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                throw ValidationException::withMessages(['email' => ['The account could not be registered.']]);
            }
            try {
                $user->forceFill(['name' => $data['name'], 'email' => $email,
                    $user->getAuthPasswordName() => Hash::make($data['password'])])->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['email' => ['The account could not be registered.']]);
            }
            if (! $user->exists) {
                throw new \RuntimeException('The central account was not persisted.');
            }
            DB::afterCommit(static fn () => event(new Registered($user)));
            app(Audit::class)->record('auth.registered', ['subject' => (string) $user->getAuthIdentifier()]);

            return $user;
        });
    }
}
