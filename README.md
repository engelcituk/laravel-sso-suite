# Laravel SSO Composer distribution

Development packages from source commit `1a9c293f27bab978f0c6935da3fcb21e30499908`. No tags or releases.

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

Archives are pinned to commit `a82ed7c6d79a33f608ba0afbef67f2dbd6260388`; keep composer.lock in your application.
