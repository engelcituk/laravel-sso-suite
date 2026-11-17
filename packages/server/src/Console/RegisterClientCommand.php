<?php

declare(strict_types=1);

namespace LaravelSso\Server\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;
use LaravelSso\Contracts\InstallationConfiguration;
use LaravelSso\Server\Audit;

final class RegisterClientCommand extends Command
{
    protected $signature = 'sso:client {name} {redirect} {--public} {--first-party} {--allow-confidential-without-pkce} {--backchannel=} {--trust-backchannel : Explicitly trust this exact back-channel URL in the SSO environment} {--post-logout=}';

    protected $description = 'Register a Passport authorization-code client and its OIDC metadata';

    public function handle(ClientRepository $clients): int
    {
        if ($this->option('public') && $this->option('allow-confidential-without-pkce')) {
            $this->error('Public clients always require S256 PKCE.');

            return self::FAILURE;
        }
        $redirect = $this->argument('redirect');
        $parts = parse_url($redirect);
        $schemes = config('sso-server.allow_http') && ! app()->environment('production') ? ['http', 'https'] : ['https'];
        if (! filter_var($redirect, FILTER_VALIDATE_URL) || ! in_array($parts['scheme'] ?? '', $schemes, true)
            || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            $this->error('An exact, trusted HTTPS redirect URI is required.');

            return self::FAILURE;
        }
        foreach (['backchannel', 'post-logout'] as $option) {
            $url = $this->option($option);
            if ($url === null) {
                continue;
            }
            $parts = parse_url($url);
            if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array($parts['scheme'] ?? '', $schemes, true)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
                || ($option === 'backchannel' && ! in_array($url, config('sso-server.backchannel_allowed_urls'), true) && ! $this->option('trust-backchannel'))) {
                $this->error('Logout URLs must be trusted HTTPS endpoints; back-channel also requires the exact outbound allowlist entry.');

                return self::FAILURE;
            }
        }
        $backchannel = $this->option('backchannel');
        if ($this->option('trust-backchannel') && is_string($backchannel)
            && ! in_array($backchannel, config('sso-server.backchannel_allowed_urls'), true)) {
            if (str_contains($backchannel, ',')) {
                $this->error('Encode commas in the trusted URL before storing the outbound allowlist.');

                return self::FAILURE;
            }
            $allowed = array_values(array_unique([...config('sso-server.backchannel_allowed_urls'), $backchannel]));
            try {
                app(InstallationConfiguration::class)->write($this->laravel->environmentFilePath(), ['SSO_BACKCHANNEL_ALLOWED_URLS' => implode(',', $allowed)]);
            } catch (\RuntimeException $error) {
                $this->error($error->getMessage());

                return self::FAILURE;
            }
            config(['sso-server.backchannel_allowed_urls' => $allowed]);
            $this->call('config:clear');
        }
        $client = DB::transaction(function () use ($clients, $redirect) {
            $client = $clients->createAuthorizationCodeGrantClient($this->argument('name'), [$redirect], ! $this->option('public'));
            DB::table('sso_clients')->insert(['client_id' => $client->getKey(), 'auto_approve' => $this->option('first-party'),
                'require_pkce' => ! $this->option('allow-confidential-without-pkce'),
                'backchannel_logout_uri' => $this->option('backchannel'),
                'post_logout_redirect_uris' => $this->option('post-logout') ? json_encode([$this->option('post-logout')]) : null,
            ]);
            app(Audit::class)->record('client.registered', ['client' => (string) $client->getKey()]);

            return $client;
        });
        $this->line('SSO_CLIENT_ID='.$client->getKey());
        if (! $this->option('public')) {
            $this->line('SSO_CLIENT_SECRET='.$client->plainSecret);
            $this->warn('Copy the secret to the RP secret store now; it cannot be recovered later.');
        }

        return self::SUCCESS;
    }
}
