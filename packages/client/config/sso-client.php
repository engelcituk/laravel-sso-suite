<?php

return [
    'audit_log' => env('SSO_CLIENT_AUDIT_LOG', false),
    'audit_channel' => env('SSO_CLIENT_AUDIT_CHANNEL'),
    'enabled' => env('SSO_CLIENT_ENABLED', false),
    'issuer' => env('SSO_ISSUER', 'https://identity.example.test'),
    'client_id' => env('SSO_CLIENT_ID'),
    'client_secret' => env('SSO_CLIENT_SECRET'),
    'redirect_uri' => env('SSO_REDIRECT_URI'),
    'guard' => 'web',
    'user_model' => env('SSO_USER_MODEL', 'App\\Models\\User'),
    'provision' => 'restricted', // automatic: create identities without privileges
    'allowed' => null, // optional callable(Authenticatable, Identity): bool
    'scopes' => ['openid', 'profile', 'email'],
    'transaction_ttl' => 300,
    'clock_skew' => 30,
    'max_token_age' => 300,
    'max_age' => null, // Optional OIDC reauthentication policy, validated again in the ID Token.
    'session_check_interval' => 30,
    'local_session_ttl' => 300, // Upper bound for a local index; shorter token lifetimes take precedence.
    'receipt_retention' => 86400, // Pruning enforces at least max_token_age + 2*clock_skew + 1.
    'session_status_url' => env('SSO_SESSION_STATUS_URL'),
    'allow_http' => false,
    // Exact operator-trusted URLs, including discovery and all advertised endpoints.
    'allowed_urls' => [],
    'timeout' => 3,
    'after_login' => '/',
];
