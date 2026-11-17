<?php

declare(strict_types=1);

namespace LaravelSso\Server\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LaravelSso\Server\Accounts;
use LaravelSso\Server\ClientUsers;
use LaravelSso\Server\PasswordRecovery;

final class CreateUserCommand extends Command
{
    protected $signature = 'sso:user {email} {--name=} {--client=* : Application IDs to authorize} {--send-reset : Send a one-use link instead of entering an initial password}';

    protected $description = 'Create a new central account and explicitly assign applications without updating existing users';

    public function handle(Accounts $accounts, ClientUsers $access, PasswordRecovery $recovery): int
    {
        if (! $this->option('send-reset') && ! $this->input->isInteractive()) {
            $this->error('Use the hidden password prompt, or --send-reset with configured mail. Password CLI arguments are not accepted.');

            return self::FAILURE;
        }
        try {
            if ($this->option('send-reset')) {
                $recovery->settings();
            }
            $password = $this->option('send-reset') ? bin2hex(random_bytes(32)) : $this->secret('Initial central password');
            $confirmation = $this->option('send-reset') ? $password : $this->secret('Confirm central password');
            $user = DB::transaction(function () use ($accounts, $access, $password, $confirmation) {
                $user = $accounts->create(['name' => $this->option('name') ?: $this->argument('email'), 'email' => $this->argument('email'),
                    'password' => $password, 'password_confirmation' => $confirmation]);
                foreach ($this->option('client') as $client) {
                    $access->assign($client, (string) $user->getAuthIdentifier());
                }

                return $user;
            });
        } catch (ValidationException $error) {
            $this->error(implode(' ', $error->validator->errors()->all()));

            return self::FAILURE;
        } catch (\RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $this->line('SSO_SUBJECT='.$user->getAuthIdentifier());
        if ($this->option('send-reset')) {
            try {
                $email = $user->getAttribute('email');
                if (! is_string($email)) {
                    throw new \RuntimeException('The account email must be a string.');
                }
                $recovery->send($email);
            } catch (\Throwable) {
                $this->error('Account created, but reset mail delivery failed. Check private mail configuration and retry recovery.');

                return self::FAILURE;
            }
            $this->info('Password setup notification submitted to the configured mail transport.');
        }

        return self::SUCCESS;
    }
}
