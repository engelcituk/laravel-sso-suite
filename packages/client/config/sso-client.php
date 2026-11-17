<?php

$guard = env('SSO_CLIENT_GUARD', config('auth.defaults.guard', 'web'));
$provider = config('auth.guards.'.$guard.'.provider');
$issuer = env('SSO_ISSUER', 'https://identity.example.test');

return [
    'audit_log' => env('SSO_CLIENT_AUDIT_LOG', false),
    'audit_channel' => env('SSO_CLIENT_AUDIT_CHANNEL'),
    'enabled' => env('SSO_CLIENT_ENABLED', false),
    'schedule' => true,
    'issuer' => $issuer,
    'client_id' => env('SSO_CLIENT_ID'),
    'client_secret' => env('SSO_CLIENT_SECRET'),
    'redirect_uri' => env('SSO_REDIRECT_URI'),
    'guard' => $guard,
    'user_model' => env('SSO_USER_MODEL', config('auth.providers.'.$provider.'.model', 'App\\Models\\User')),
    'integrate_auth' => true,
    'local_auth_routes' => ['login', 'login.*', 'logout', 'register', 'register.*', 'password.*', 'two-factor.login', 'two-factor.login.*', 'verification.*'],
    'local_auth_paths' => ['login', 'logout', 'register', 'forgot-password', 'reset-password', 'reset-password/*', 'two-factor-challenge', 'email/verify', 'email/verify/*', 'email/verification-notification', 'user/confirm-password'],
    'provision' => env('SSO_CLIENT_PROVISION', 'greenfield'), // greenfield: built-in new accounts; restricted: operator links; automatic: custom factory.
    // Prefer a cacheable invokable class name: __invoke(Identity): Authenticatable.
    // Used only by automatic mode; never receives tokens/passwords. A callable is also supported.
    'create_user' => null,
    'local_login_url' => '/login',
    'allowed' => null, // optional callable(Authenticatable, Identity): bool
    'scopes' => ['openid', 'profile', 'email'],
    'transaction_ttl' => 300,
    'clock_skew' => 30,
    'max_token_age' => 300,
    'max_age' => null, // Optional OIDC reauthentication policy, validated again in the ID Token.
    'session_check_interval' => 30,
    'local_session_ttl' => 300, // Upper bound for a local index; shorter token lifetimes take precedence.
    'receipt_retention' => 86400, // Pruning enforces at least max_token_age + 2*clock_skew + 1.
    'session_status_url' => env('SSO_SESSION_STATUS_URL', $issuer.'/oidc/session'),
    'allow_http' => env('SSO_CLIENT_ALLOW_HTTP', false),
    // Exact operator-trusted URLs, including discovery and all advertised endpoints.
    'allowed_urls' => array_map(static fn ($path) => $issuer.$path, ['/.well-known/openid-configuration', '/oidc/authorize', '/oidc/token', '/oidc/jwks', '/oidc/userinfo', '/oidc/session', '/oidc/logout']),
    'timeout' => 3,
    'after_login' => '/',
];
