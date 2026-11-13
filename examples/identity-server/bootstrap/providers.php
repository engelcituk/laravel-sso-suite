<?php

use Laravel\Passport\PassportServiceProvider;
use LaravelSso\Server\SsoServerServiceProvider;

return [PassportServiceProvider::class, SsoServerServiceProvider::class];
