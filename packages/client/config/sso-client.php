<?php

return [
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
    'session_check_interval' => 30,
    'session_status_url' => env('SSO_SESSION_STATUS_URL'),
    'allow_http' => false,
    // Exact operator-trusted URLs, including discovery and all advertised endpoints.
    'allowed_urls' => [],
    'timeout' => 3,
    'after_login' => '/',
];
