<?php

declare(strict_types=1);

namespace LaravelSso\Server\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use LaravelSso\Server\ClientChanges;
use LaravelSso\Server\Contracts\ManagesClients;

final class UpdateClientCommand extends Command
{
    protected $signature = 'sso:client-update {client} {--name=} {--redirect=} {--first-party= : 0 or 1} {--require-pkce= : 0 or 1} {--restrict-users= : 0 or 1} {--backchannel= : Empty clears this URL; nonempty must already be trusted} {--post-logout= : Empty clears this URL}';

    protected $description = 'Edit an active SSO application and invalidate its existing codes and tokens';

    public function handle(ManagesClients $clients): int
    {
        try {
            $flags = ['first-party' => $this->option('first-party'), 'require-pkce' => $this->option('require-pkce'), 'restrict-users' => $this->option('restrict-users')];
            validator(array_filter($flags, fn ($value) => $value !== null), array_fill_keys(array_keys($flags), 'sometimes|required|in:0,1'))->validate();
            $client = $clients->update($this->argument('client'), new ClientChanges(name: $this->option('name'),
                redirectUri: $this->option('redirect'), firstParty: $flags['first-party'] === null ? null : $flags['first-party'] === '1',
                requirePkce: $flags['require-pkce'] === null ? null : $flags['require-pkce'] === '1',
                restrictUsers: $flags['restrict-users'] === null ? null : $flags['restrict-users'] === '1',
                backchannelLogoutUri: $this->option('backchannel'), postLogoutRedirectUri: $this->option('post-logout')));
            $this->line(json_encode($client, JSON_THROW_ON_ERROR));
        } catch (ValidationException $error) {
            $this->error(implode(' ', $error->validator->errors()->all()));

            return self::FAILURE;
        } catch (ModelNotFoundException|\RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
