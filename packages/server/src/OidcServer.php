<?php

declare(strict_types=1);

namespace LaravelSso\Server;

use DateInterval;
use Laravel\Passport\Bridge;
use Laravel\Passport\Passport;
use LaravelSso\Server\Bridge\AuthCodeRepository;
use LaravelSso\Server\Bridge\IdTokenResponse;
use LaravelSso\Server\Bridge\NoRefreshTokenRepository;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;

/** Isolated League server using Passport persistence; leaves host OAuth grants unchanged. */
final class OidcServer
{
    public function make(): AuthorizationServer
    {
        if (config('passport.connection') !== null && config('passport.connection') !== config('database.default')) {
            throw new \RuntimeException('SSO and Passport must use the same default database connection.');
        }
        $key = config('passport.private_key') ?: 'file://'.Passport::keyPath('oauth-private.key');
        $server = new AuthorizationServer(
            app(Bridge\ClientRepository::class), app(Bridge\AccessTokenRepository::class), app(Bridge\ScopeRepository::class),
            new CryptKey(str_replace('\\n', "\n", $key), null, false),
            Passport::tokenEncryptionKey(app('encrypter')), new IdTokenResponse,
        );
        $server->enableGrantType(new AuthCodeGrant(
            new AuthCodeRepository, app(NoRefreshTokenRepository::class),
            new DateInterval('PT'.(int) config('sso-server.code_ttl').'S'),
        ), new DateInterval('PT'.(int) config('sso-server.access_token_ttl').'S'));

        return $server;
    }
}
