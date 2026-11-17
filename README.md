# Laravel SSO Composer distribution

Development packages from source commit `fba4d6fa7446e0e52adcd92048b603a02f5357f7`. No tags or releases.

Configure your Laravel host:

```sh
composer config repositories.laravel-sso composer https://raw.githubusercontent.com/engelcituk/laravel-sso-suite/composer
```

Server:

```sh
composer require laravel-sso/server:0.1.x-dev laravel-sso/contracts:0.1.x-dev --prefer-dist
```

Client:

```sh
composer require laravel-sso/client:0.1.x-dev laravel-sso/contracts:0.1.x-dev --prefer-dist
```

Archives are pinned to commit `f7a0636b3c73575cf82e5efd9b2f0a25f74bcb08`; keep composer.lock in your application.
