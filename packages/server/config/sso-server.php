<?php

return [
    'audit_log' => env('SSO_SERVER_AUDIT_LOG', false),
    'audit_channel' => env('SSO_SERVER_AUDIT_CHANNEL'),
    'enabled' => env('SSO_SERVER_ENABLED', false),
    'schedule' => true,
    'issuer' => env('SSO_ISSUER', 'https://identity.example.test'),
    'guard' => env('SSO_SERVER_GUARD', config('auth.defaults.guard', 'web')),
    'active_column' => env('SSO_SERVER_ACTIVE_COLUMN'),
    'authentication' => env('SSO_SERVER_AUTHENTICATION', 'auto'), // auto: preserve host login/MFA; package: explicitly use the optional password backend.
    'login_route' => 'login',
    'login_url' => env('SSO_SERVER_LOGIN_URL'),
    'login_ui' => env('SSO_SERVER_LOGIN_UI', true),
    'login_view' => null,
    'login_field' => 'email',
    'after_login' => '/',
    // Optional model attributes. Missing values are omitted, never synthesized.
    'claim_attributes' => [
        'name' => 'name', 'given_name' => 'given_name', 'family_name' => 'family_name',
        'middle_name' => 'middle_name', 'nickname' => 'nickname', 'preferred_username' => 'preferred_username',
        'profile' => 'profile', 'picture' => 'picture', 'website' => 'website', 'gender' => 'gender',
        'birthdate' => 'birthdate', 'zoneinfo' => 'zoneinfo', 'locale' => 'locale', 'updated_at' => 'updated_at',
        'email' => 'email', 'email_verified' => 'email_verified_at',
    ],
    'allow_http' => env('SSO_SERVER_ALLOW_HTTP', false),
    'id_token_ttl' => 300,
    'access_token_ttl' => 300,
    'code_ttl' => 300,
    'session_idle' => 1800,
    'session_max' => 28800,
    'logout_hint_retention' => 86400, // Accept signed expired hints only for current/recent RP session bindings.
    'state_retention' => 86400, // Additional retention after expiry; minimum one hour during pruning.
    'remember_enabled' => true,
    'remember_ttl' => 2592000, // Absolute 30 days, never extended by cookie rotation.
    'remember_cookie' => 'sso_remember', // __Host- prefix is applied when HTTPS is required.
    'devices_path' => 'sso/devices',
    'device_password_timeout' => 300,
    'device_record_ip' => false,
    'signing_kid' => env('SSO_SIGNING_KID', 'initial'),
    'private_key' => env('SSO_PRIVATE_KEY', storage_path('sso/private.pem')),
    // Retain old PUBLIC keys until all tokens they signed have expired.
    'public_keys' => ['initial' => storage_path('sso/public.pem')],
    'backchannel_allowed_urls' => array_values(array_filter(explode(',', env('SSO_BACKCHANNEL_ALLOWED_URLS', '')))),
    'backchannel_timeout' => 3,
    'protocol_throttle' => '120,1,sso-protocol',
];
