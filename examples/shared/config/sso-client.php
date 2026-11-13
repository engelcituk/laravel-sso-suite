<?php

use LaravelSso\Examples\LocalUser;

$config = require dirname(__DIR__, 3).'/packages/client/config/sso-client.php';
$config['allow_http'] = env('APP_ENV') === 'local';
$config['provision'] = 'automatic';
$config['user_model'] = LocalUser::class;
$config['session_check_interval'] = 5;
$config['allowed_urls'] = array_map(static fn ($path) => env('SSO_ISSUER').$path, [
    '/.well-known/openid-configuration', '/oidc/authorize', '/oidc/token', '/oidc/jwks', '/oidc/userinfo', '/oidc/session', '/oidc/logout',
]);

return $config;
