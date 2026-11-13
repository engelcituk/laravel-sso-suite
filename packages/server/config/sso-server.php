<?php

return [
    'enabled' => env('SSO_SERVER_ENABLED', false),
    'issuer' => env('SSO_ISSUER', 'https://identity.example.test'),
    'guard' => 'web',
    'active_column' => 'is_active',
    'allow_http' => false,
    'id_token_ttl' => 300,
    'access_token_ttl' => 300,
    'code_ttl' => 300,
    'session_idle' => 1800,
    'session_max' => 28800,
    'signing_kid' => env('SSO_SIGNING_KID', 'initial'),
    'private_key' => env('SSO_PRIVATE_KEY', storage_path('sso/private.pem')),
    // Retain old PUBLIC keys until all tokens they signed have expired.
    'public_keys' => ['initial' => storage_path('sso/public.pem')],
    'backchannel_allowed_urls' => [],
    'backchannel_timeout' => 3,
    'protocol_throttle' => '120,1,sso-protocol',
];
