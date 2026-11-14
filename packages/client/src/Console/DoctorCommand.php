<?php

declare(strict_types=1);

namespace LaravelSso\Client\Console;

use Firebase\JWT\JWK;
use Illuminate\Auth\SessionGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use LaravelSso\Client\Provider;
use LaravelSso\Client\TrustedHttp;
use LaravelSso\Contracts\DiagnosticReport;

final class DoctorCommand extends Command
{
    protected $signature = 'sso:doctor {--offline : Run only local checks without HTTP} {--json : Emit a versioned report without secrets}';

    protected $description = 'Verify configured discovery, trusted endpoints and signing keys without printing secrets';

    public function handle(Provider $provider, TrustedHttp $http): int
    {
        $report = new DiagnosticReport;
        $report->check('enabled', 'Enable sso-client after configuration.', fn () => config('sso-client.enabled'));
        $report->check('identity', 'Configure a SessionGuard for the local Eloquent identity model with a unique (issuer, subject) index and no credentials.', function (): bool {
            $model = config('sso-client.user_model');
            $user = new $model;
            $schema = $user->getConnection()->getSchemaBuilder();
            $guard = config('sso-client.guard');
            $provider = config('auth.guards.'.$guard.'.provider');
            if (! Auth::guard($guard) instanceof SessionGuard || config('auth.providers.'.$provider.'.model') !== $model
                || ! $schema->hasColumns($user->getTable(), ['sso_issuer', 'sso_subject'])
                || $schema->hasColumn($user->getTable(), $user->getAuthPasswordName()) || $schema->hasColumn($user->getTable(), 'remember_token')) {
                return false;
            }
            foreach ($schema->getIndexes($user->getTable()) as $index) {
                if ($index['unique'] && $index['columns'] === ['sso_issuer', 'sso_subject']) {
                    if ($user->getConnection()->getDriverName() === 'mysql') {
                        foreach ($schema->getColumns($user->getTable()) as $column) {
                            if (in_array($column['name'], ['sso_issuer', 'sso_subject'], true)
                                && ! preg_match('/_(bin|cs)$/D', $column['collation'] ?? '')) {
                                return false;
                            }
                        }
                    }

                    return true;
                }
            }

            return false;
        });
        $report->check('schema', 'Apply the single client migration for local sessions and logout receipts.', fn () => Schema::hasColumns('sso_local_sessions', ['session_hash', 'issuer', 'subject', 'sid', 'expires_at', 'revoked'])
            && Schema::hasColumns('sso_logout_receipts', ['token_hash', 'received_at'])
            && count(glob(database_path('migrations/*_create_sso_local_sessions.php')) ?: []) <= 1);
        $report->check('session', 'Use server-side sessions and secure host-only cookies in production.', fn () => ! in_array(config('session.driver'), ['cookie', 'null'], true)
            && (! app()->environment('production') || (config('session.driver') !== 'array' && config('session.secure')
                && ! config('session.domain') && config('session.http_only') && config('session.path') === '/')));
        $report->check('configuration', 'Set client ID, HTTPS callback, provisioning policy, openid scope and positive TTLs.', function (): bool {
            $uri = config('sso-client.redirect_uri');
            $parts = is_string($uri) ? parse_url($uri) : false;

            return is_string(config('sso-client.client_id')) && config('sso-client.client_id') !== ''
                && $parts && filter_var($uri, FILTER_VALIDATE_URL) && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment'])
                && (($parts['scheme'] ?? '') === 'https' || (config('sso-client.allow_http') && ! app()->environment('production') && ($parts['scheme'] ?? '') === 'http'))
                && in_array(config('sso-client.provision'), ['restricted', 'automatic'], true)
                && in_array('openid', config('sso-client.scopes'), true)
                && min(array_map(static fn ($key) => (int) config('sso-client.'.$key), ['transaction_ttl', 'local_session_ttl', 'max_token_age', 'receipt_retention', 'timeout'])) > 0;
        });
        $report->check('trusted_urls', 'Trust discovery and session status URLs explicitly; HTTPS required outside development.', function () use ($http): bool {
            $issuer = config('sso-client.issuer');
            if (! is_string($issuer) || $issuer === '' || str_ends_with($issuer, '/')) {
                return false;
            }
            $http->request($issuer.'/.well-known/openid-configuration');
            $http->request(config('sso-client.session_status_url'));

            return true;
        });
        if (! $this->option('offline')) {
            $report->check('discovery_jwks', 'Verify issuer, trusted endpoints, S256, RS256 and usable JWKS over HTTP.', function () use ($provider, $http): bool {
                $metadata = $provider->metadata();
                $keys = $http->get($metadata['jwks_uri']);
                $rsa = array_filter($keys['keys'] ?? [], static fn ($key) => is_array($key) && ($key['kty'] ?? null) === 'RSA'
                    && ($key['alg'] ?? 'RS256') === 'RS256' && ($key['use'] ?? 'sig') === 'sig'
                    && is_string($key['n'] ?? null) && strlen(base64_decode(strtr($key['n'], '-_', '+/'), true) ?: '') >= 256);

                return $rsa !== [] && JWK::parseKeySet(['keys' => array_values($rsa)], 'RS256') !== [];
            });
        }
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(['Check', 'Status', 'Action / requirement'], $report->checks);
            $this->line('This snapshot does not establish OIDC conformance or validate a login. Verify TLS/proxy, scheduler and deployment ACLs separately.');
        }

        return $report->passed() ? self::SUCCESS : self::FAILURE;
    }
}
