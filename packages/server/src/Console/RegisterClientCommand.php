<?php

declare(strict_types=1);

namespace LaravelSso\Server\Console;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use LaravelSso\Contracts\InstallationConfiguration;
use LaravelSso\Server\ClientDefinition;
use LaravelSso\Server\Contracts\RegistersClients;

final class RegisterClientCommand extends Command
{
    protected $signature = 'sso:client {name} {redirect} {--public} {--first-party} {--allow-all-users : Explicitly allow every eligible central account instead of assigned users only} {--allow-confidential-without-pkce} {--backchannel=} {--trust-backchannel : Explicitly trust this exact back-channel URL in the SSO environment} {--post-logout=}';

    protected $description = 'Register a Passport authorization-code client and its OIDC metadata';

    public function handle(RegistersClients $clients): int
    {
        $definition = new ClientDefinition(name: $this->argument('name'), redirectUri: $this->argument('redirect'),
            public: (bool) $this->option('public'), firstParty: (bool) $this->option('first-party'),
            requirePkce: ! $this->option('allow-confidential-without-pkce'), restrictUsers: ! $this->option('allow-all-users'),
            backchannelLogoutUri: $this->option('backchannel'), postLogoutRedirectUri: $this->option('post-logout'));
        $backchannel = $this->option('backchannel');
        try {
            $clients->validate($definition, (bool) $this->option('trust-backchannel'));
            if ($this->option('trust-backchannel') && is_string($backchannel)
                && ! in_array($backchannel, config('sso-server.backchannel_allowed_urls'), true)) {
                if (str_contains($backchannel, ',')) {
                    throw new \RuntimeException('Encode commas in the trusted URL before storing the outbound allowlist.');
                }
                $allowed = array_values(array_unique([...config('sso-server.backchannel_allowed_urls'), $backchannel]));
                app(InstallationConfiguration::class)->write($this->laravel->environmentFilePath(), ['SSO_BACKCHANNEL_ALLOWED_URLS' => implode(',', $allowed)]);
                config(['sso-server.backchannel_allowed_urls' => $allowed]);
                $this->call('config:clear');
            }
            $client = $clients->create($definition);
        } catch (ValidationException $error) {
            $this->error(implode(' ', $error->validator->errors()->all()));

            return self::FAILURE;
        } catch (\RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $this->line('SSO_CLIENT_ID='.$client->id);
        if ($client->plainSecret() !== null) {
            $this->line('SSO_CLIENT_SECRET='.$client->plainSecret());
            $this->warn('Copy the secret to the RP secret store now; it cannot be recovered later.');
        }

        return self::SUCCESS;
    }
}
