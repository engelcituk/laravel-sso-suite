<?php

declare(strict_types=1);

namespace LaravelSso\Server\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use LaravelSso\Server\Audit;

final class KeysCommand extends Command
{
    protected $signature = 'sso:keys';

    protected $description = 'Create the configured OIDC RSA key pair without overwriting existing keys';

    public function handle(): int
    {
        $privatePath = config('sso-server.private_key');
        $publicPath = config('sso-server.public_keys')[config('sso-server.signing_kid')] ?? null;
        if (! is_string($privatePath) || ! is_string($publicPath) || file_exists($privatePath) || file_exists($publicPath)) {
            $this->error('Configure two new key paths. Existing keys are never overwritten.');

            return self::FAILURE;
        }
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072]);
        if ($key === false || ! openssl_pkey_export($key, $private) || ! ($details = openssl_pkey_get_details($key))) {
            $this->error('OpenSSL key generation failed; verify OPENSSL_CONF on Windows.');

            return self::FAILURE;
        }
        File::ensureDirectoryExists(dirname($privatePath), 0700);
        File::ensureDirectoryExists(dirname($publicPath), 0700);
        $handle = fopen($privatePath, 'x');
        if ($handle === false) {
            return self::FAILURE;
        }
        chmod($privatePath, 0600);
        fwrite($handle, $private);
        fclose($handle);
        $handle = fopen($publicPath, 'x');
        if ($handle === false) {
            return self::FAILURE;
        }
        fwrite($handle, $details['key']);
        fclose($handle);
        $this->info('OIDC keys created. Configure filesystem ACLs on Windows.');
        app(Audit::class)->record('keys.created');

        return self::SUCCESS;
    }
}
