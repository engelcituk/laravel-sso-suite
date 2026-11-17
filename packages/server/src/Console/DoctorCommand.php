<?php

declare(strict_types=1);

namespace LaravelSso\Server\Console;

use Illuminate\Auth\SessionGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use LaravelSso\Contracts\DiagnosticReport;
use LaravelSso\Server\Protocol\RedirectUri;
use LaravelSso\Server\SigningKeys;

final class DoctorCommand extends Command
{
    protected $signature = 'sso:doctor-server {--json : Emit a versioned report without secrets}';

    protected $description = 'Check local OP configuration, schema and signing keys without changing state or sending HTTP';

    public function handle(): int
    {
        $report = new DiagnosticReport;
        $report->check('enabled', 'Enable sso-server after configuring the dedicated issuer.', fn () => config('sso-server.enabled'));
        $report->check('issuer', 'Issuer must be a dedicated HTTPS origin; HTTP is development only.', function (): bool {
            $issuer = config('sso-server.issuer');
            $parts = is_string($issuer) ? parse_url($issuer) : false;

            return $parts && filter_var($issuer, FILTER_VALIDATE_URL)
                && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment'])
                && ! isset($parts['path']) && (($parts['scheme'] ?? '') === 'https'
                    || (config('sso-server.allow_http') && ! app()->environment('production') && ($parts['scheme'] ?? '') === 'http'));
        });
        $report->check('guard', 'Use SessionGuard and an Eloquent provider; an active column is optional and must exist when configured.', function (): bool {
            $guardName = config('sso-server.guard');
            $provider = config('auth.guards.'.$guardName.'.provider');
            $model = config('auth.providers.'.$provider.'.model');
            $user = new $model;

            $column = config('sso-server.active_column');

            return Auth::guard($guardName) instanceof SessionGuard && config('auth.providers.'.$provider.'.driver') === 'eloquent'
                && $user->getConnection()->getSchemaBuilder()->hasColumn($user->getTable(), $user->getAuthPasswordName())
                && ($column === null || (is_string($column) && $column !== '' && $user->getConnection()->getSchemaBuilder()->hasColumn($user->getTable(), $column)));
        });
        $report->check('session', 'Use server-side sessions and secure host-only cookies in production.', fn () => ! in_array(config('session.driver'), ['cookie', 'null'], true)
            && (! app()->environment('production') || (! in_array(config('session.driver'), ['array'], true)
                && config('session.secure') && ! config('session.domain') && config('session.http_only') && config('session.path') === '/')));
        $report->check('schema', 'Apply Passport migrations and the single server migration.', function (): bool {
            foreach (['sso_clients' => ['client_id', 'require_pkce'], 'sso_sessions' => ['sid', 'device_id', 'revoked'],
                'sso_auth_contexts' => ['nonce'], 'sso_token_sessions' => ['token_id'], 'sso_logout_deliveries' => ['attempts', 'delivered_at'],
                'sso_devices' => ['credential_hash', 'remember_until'], 'sso_remember_tokens' => ['token_hash', 'used_at'],
                'oauth_clients' => ['id'], 'oauth_access_tokens' => ['revoked'], 'oauth_auth_codes' => ['revoked']] as $table => $columns) {
                if (! Schema::hasColumns($table, $columns)) {
                    return false;
                }
            }

            return count(glob(database_path('migrations/*_create_sso_tables.php')) ?: []) <= 1;
        });
        $report->check('signing_keys', 'Readable RSA keys (2048+ bits), published signing kid and matching private/public pair required.', function (): bool {
            foreach (array_merge([config('sso-server.private_key')], array_values(config('sso-server.public_keys'))) as $path) {
                if (! is_string($path) || ! is_readable($path)) {
                    return false;
                }
            }
            $keys = app(SigningKeys::class)->jwks();
            $kid = config('sso-server.signing_kid');
            $path = config('sso-server.public_keys')[$kid] ?? null;
            $private = openssl_pkey_get_private(file_get_contents(config('sso-server.private_key')));
            $public = $path ? openssl_pkey_get_public(file_get_contents($path)) : false;
            if (! $private || ! $public || $keys['keys'] === []) {
                return false;
            }
            openssl_sign('sso-doctor', $signature, $private, OPENSSL_ALGO_SHA256);

            return openssl_verify('sso-doctor', $signature, $public, OPENSSL_ALGO_SHA256) === 1;
        });
        $report->check('retention', 'Positive session, remember and retention bounds required.', fn () => min(array_map(static fn ($key) => (int) config('sso-server.'.$key),
            ['session_idle', 'session_max', 'remember_ttl', 'state_retention', 'logout_hint_retention', 'code_ttl', 'access_token_ttl', 'id_token_ttl'])) > 0);
        $report->check('passport', 'Passport must share the default DB connection and have a matching readable OAuth RSA key pair.', function (): bool {
            if (config('passport.connection') !== null && config('passport.connection') !== config('database.default')) {
                return false;
            }
            $pems = [];
            foreach (['private', 'public'] as $type) {
                $pem = config('passport.'.$type.'_key');
                if (! is_string($pem) || $pem === '') {
                    $path = Passport::keyPath('oauth-'.$type.'.key');
                    if (! is_readable($path)) {
                        return false;
                    }
                    $pem = file_get_contents($path);
                }
                $pems[$type] = str_replace('\\n', "\n", $pem);
            }
            $private = openssl_pkey_get_private($pems['private']);
            $public = openssl_pkey_get_public($pems['public']);
            $details = $public ? openssl_pkey_get_details($public) : false;
            if (! $private || ! $details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
                return false;
            }
            openssl_sign('sso-doctor-oauth', $signature, $private, OPENSSL_ALGO_SHA256);

            return openssl_verify('sso-doctor-oauth', $signature, $public, OPENSSL_ALGO_SHA256) === 1;
        });
        $report->check('outbox', 'Logout outbox must be readable and have no overdue retry; run sso:deliver-logouts on schedule.',
            fn () => ! DB::table('sso_logout_deliveries')->whereNull('delivered_at')->where('next_attempt', '<', time() - 300)->exists());
        $report->check('backchannel', 'Every registered logout destination must match the trusted HTTPS allowlist.', function (): bool {
            foreach (DB::table('sso_clients')->whereNotNull('backchannel_logout_uri')->pluck('backchannel_logout_uri') as $url) {
                if (! is_string($url) || ! app(RedirectUri::class)->safe($url)
                    || ! in_array($url, config('sso-server.backchannel_allowed_urls'), true)) {
                    return false;
                }
            }

            return true;
        });
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(['Check', 'Status', 'Action / requirement'], $report->checks);
            $this->line('Local snapshot only. Verify scheduler, queue, TLS/proxy and filesystem ACLs in the deployment. No OIDC certification implied.');
        }

        return $report->passed() ? self::SUCCESS : self::FAILURE;
    }
}
