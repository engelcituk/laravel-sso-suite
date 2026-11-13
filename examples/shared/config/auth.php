<?php

use LaravelSso\Examples\CentralUser;
use LaravelSso\Examples\LocalUser;

return [
    'defaults' => ['guard' => 'web', 'passwords' => 'users'],
    'guards' => ['web' => ['driver' => 'session', 'provider' => 'users']],
    'providers' => ['users' => ['driver' => 'eloquent', 'model' => env('SSO_SERVER_ENABLED', false)
        ? CentralUser::class : LocalUser::class]],
];
