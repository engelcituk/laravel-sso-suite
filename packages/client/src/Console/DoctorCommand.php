<?php

declare(strict_types=1);

namespace LaravelSso\Client\Console;

use Firebase\JWT\JWK;
use Illuminate\Auth\SessionGuard;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        if (! config('sso-client.enabled')) {
            $report->check('mode', 'Local Laravel authentication is selected; no SSO checks or network requests.', fn () => true);

            return $this->outputReport($report);
        }
        $report->check('enabled', 'Central authentication is selected.', fn () => true);
        $report->check('identity', 'Configure a SessionGuard with the host Eloquent user model on the client connection.', function (): bool {
            $model = config('sso-client.user_model');
            $user = new $model;
            $guard = config('sso-client.guard');
            $provider = config('auth.guards.'.$guard.'.provider');

            return $user instanceof Model
                && $user instanceof Authenticatable
                && Auth::guard($guard) instanceof SessionGuard
                && config('auth.providers.'.$provider.'.model') === $model
                && $user->getConnection()->getName() === DB::connection()->getName()
                && $user->getConnection()->getSchemaBuilder()->hasColumn($user->getTable(), $user->getKeyName());
        });
        $report->check('schema', 'Apply the single client migration for owned identities, sessions and receipts.', function (): bool {
            if (! Schema::hasColumns('sso_identities', ['issuer', 'subject', 'user_id', 'user_type'])
                || ! Schema::hasIndex('sso_identities', ['issuer', 'subject'], 'unique')
                || ! Schema::hasColumns('sso_local_sessions', ['session_hash', 'issuer', 'subject', 'sid', 'user_id', 'user_type', 'expires_at', 'revoked'])
                || ! Schema::hasColumns('sso_logout_receipts', ['token_hash', 'received_at'])
                || count(glob(database_path('migrations/*_create_sso_local_sessions.php')) ?: []) > 1) {
                return false;
            }
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                foreach (Schema::getColumns('sso_identities') as $column) {
                    if (in_array($column['name'], ['issuer', 'subject'], true)
                        && ! preg_match('/_(bin|cs)$/D', $column['collation'] ?? '')) {
                        return false;
                    }
                }
            }

            return true;
        });
        $report->check('session', 'Use server-side sessions and secure host-only cookies in production.', fn () => ! in_array(config('session.driver'), ['cookie', 'null'], true)
            && (! app()->environment('production') || (config('session.driver') !== 'array' && config('session.secure')
                && ! config('session.domain') && config('session.http_only') && config('session.path') === '/')));
        $report->check('configuration', 'Set client ID, HTTPS callback, provisioning policy, openid scope and positive TTLs.', function (): bool {
            $uri = config('sso-client.redirect_uri');
            $parts = is_string($uri) ? parse_url($uri) : false;
            $factory = config('sso-client.create_user');
            if (is_string($factory) && class_exists($factory)) {
                $factory = app($factory);
            }

            return is_string(config('sso-client.client_id')) && config('sso-client.client_id') !== ''
                && $parts && filter_var($uri, FILTER_VALIDATE_URL) && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment'])
                && (($parts['scheme'] ?? '') === 'https' || (config('sso-client.allow_http') && ! app()->environment('production') && ($parts['scheme'] ?? '') === 'http'))
                && in_array(config('sso-client.provision'), ['restricted', 'automatic'], true)
                && (config('sso-client.provision') !== 'automatic' || is_callable($factory))
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

        return $this->outputReport($report);
    }

    private function outputReport(DiagnosticReport $report): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(['Check', 'Status', 'Action / requirement'], $report->checks);
            $this->line('This snapshot does not establish OIDC conformance or validate a login. Verify TLS/proxy, scheduler and deployment ACLs separately.');
        }

        return $report->passed() ? self::SUCCESS : self::FAILURE;
    }
}
