<?php

$config = require dirname(__DIR__, 3).'/packages/server/config/sso-server.php';
$config['allow_http'] = env('APP_ENV') === 'local';
$config['active_column'] = 'is_active';
$config['backchannel_allowed_urls'] = ['http://127.0.0.2:9101/sso/backchannel-logout', 'http://127.0.0.3:9102/sso/backchannel-logout'];

return $config;
