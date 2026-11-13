<?php

declare(strict_types=1);

namespace LaravelSso\Server\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;

final class RegisterClientCommand extends Command
{
    protected $signature = 'sso:client {name} {redirect} {--public} {--first-party} {--backchannel=} {--post-logout=}';

    protected $description = 'Register a Passport authorization-code client and its OIDC metadata';

    public function handle(ClientRepository $clients): int
    {
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
                || ($option === 'backchannel' && ! in_array($url, config('sso-server.backchannel_allowed_urls'), true))) {
                $this->error('Logout URLs must be trusted HTTPS endpoints; back-channel also requires the exact outbound allowlist entry.');

                return self::FAILURE;
            }
        }
        $client = DB::transaction(function () use ($clients, $redirect) {
            $client = $clients->createAuthorizationCodeGrantClient($this->argument('name'), [$redirect], ! $this->option('public'));
            DB::table('sso_clients')->insert(['client_id' => $client->getKey(), 'auto_approve' => $this->option('first-party'),
                'backchannel_logout_uri' => $this->option('backchannel'),
                'post_logout_redirect_uris' => $this->option('post-logout') ? json_encode([$this->option('post-logout')]) : null,
            ]);

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
