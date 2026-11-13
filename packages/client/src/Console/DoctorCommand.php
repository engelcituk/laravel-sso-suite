<?php

declare(strict_types=1);

namespace LaravelSso\Client\Console;

use Illuminate\Console\Command;
use LaravelSso\Client\Provider;
use LaravelSso\Client\TrustedHttp;

final class DoctorCommand extends Command
{
    protected $signature = 'sso:doctor';

    protected $description = 'Verify configured discovery, trusted endpoints and signing keys without printing secrets';

    public function handle(Provider $provider, TrustedHttp $http): int
    {
        try {
            $metadata = $provider->metadata();
            $keys = $http->get($metadata['jwks_uri']);
            if (empty($keys['keys'])) {
                throw new \RuntimeException('Empty JWKS.');
            }
            $this->info('Issuer, endpoint allowlist, S256, RS256 and JWKS connectivity verified.');
            $this->line('This does not establish OIDC conformance or validate a login.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('OIDC diagnosis failed. Check HTTPS, issuer, allowed_urls and provider availability.');

            return self::FAILURE;
        }
    }
}
