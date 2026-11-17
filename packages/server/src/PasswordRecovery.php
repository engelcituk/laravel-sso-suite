<?php

declare(strict_types=1);

namespace LaravelSso\Server;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use LaravelSso\Server\Notifications\CentralPasswordReset;

final class PasswordRecovery
{
    public function settings(): array
    {
        app(Accounts::class)->checkSchema();
        $name = config('sso-server.password_broker');
        $settings = config('auth.passwords.'.$name);
        $provider = config('auth.guards.'.config('sso-server.guard').'.provider');
        if (! is_array($settings) || ($settings['provider'] ?? null) !== $provider
            || ($settings['driver'] ?? 'database') !== 'database'
            || DB::connection($settings['connection'] ?? null)->getName() !== DB::connection()->getName()
            || ! is_string($settings['table'] ?? null) || (int) ($settings['expire'] ?? 0) < 1
            || ! app(Accounts::class)->model() instanceof CanResetPassword
            || ! DB::getSchemaBuilder()->hasColumns($settings['table'], ['email', 'token', 'created_at'])) {
            throw new \RuntimeException('Configure the server guard password broker with a database token table on the default connection.');
        }

        return $settings;
    }

    public function send(string $email): string
    {
        $settings = $this->settings();

        return Password::broker(config('sso-server.password_broker'))->sendResetLink(['email' => strtolower($email)],
            function (CanResetPassword $user, #[\SensitiveParameter] string $token) use ($settings): void {
                Notification::route('mail', $user->getEmailForPasswordReset())
                    ->notify(new CentralPasswordReset($token, $user->getEmailForPasswordReset(), (int) $settings['expire']));
                event(new PasswordResetLinkSent($user));
            });
    }

    public function reset(#[\SensitiveParameter] array $credentials): string
    {
        $settings = $this->settings();
        $credentials['email'] = strtolower($credentials['email']);

        return Transactions::run(function () use ($credentials, $settings): string {
            $user = app(Accounts::class)->model()->newQuery()->where('email', $credentials['email'])->lockForUpdate()->first();
            $row = DB::table($settings['table'])->where('email', $credentials['email'])->lockForUpdate()->first();
            if (! $user || ! $row || Carbon::parse($row->created_at)->addMinutes((int) $settings['expire'])->isPast()
                || ! Hash::check($credentials['token'], $row->token)) {
                return Password::INVALID_TOKEN;
            }

            return Password::broker(config('sso-server.password_broker'))->reset($credentials,
                function (CanResetPassword $user, #[\SensitiveParameter] string $password): void {
                    if (! $user instanceof Model || ! $user instanceof Authenticatable) {
                        throw new \RuntimeException('The broker must use the central Eloquent account model.');
                    }
                    $user->forceFill([$user->getAuthPasswordName() => Hash::make($password)]);
                    $user->setRememberToken(Str::random(60));
                    $user->save();
                    event(new PasswordReset($user)); // Native listener revokes devices, sessions and tokens in this transaction.
                });
        });
    }
}
