# Instalar el servidor de identidad

Aquí configuras la aplicación donde tus usuarios se autentican. Ejemplo:
`https://identidad.test`. La aplicación cliente será `https://ventas.test`.

**Para configurar ambos de principio a fin, sigue la [guía rápida completa](QUICKSTART.md).**
Todos los comandos de esta página se ejecutan dentro de tu aplicación **Identidad**.

## 1. Comprueba tu login actual

Necesitas Laravel 13, PHP 8.3+, Composer 2, una DB configurada y un login Laravel
funcionando con la ruta nombrada `login`, guard `web` y un usuario existente.
Los dos dominios deben tener HTTPS válido para el navegador y PHP; sustituye los ejemplos por los tuyos.

El servidor comprueba una columna de cuenta activa: `is_active` por defecto. Si tu
aplicación ya usa otra columna booleana, usa su nombre en `active_column` del paso 5.
**Si no existe una columna equivalente, esta versión necesita adaptar esa integración
antes de continuar. No añadas columnas ni cambies `users` para seguir esta guía.**
Los campos adicionales de perfil son opcionales: los ausentes se omiten.

## 2. Prepara Composer

Descarga este repositorio, por ejemplo en `C:/laragon/www/laravel-sso`.
En el `composer.json` de esta aplicación, agrega este elemento a `repositories`:

```json
{
    "type": "path",
    "url": "C:/laragon/www/laravel-sso/packages/*",
    "options": {
        "versions": {
            "laravel-sso/server": "0.1.x-dev",
            "laravel-sso/client": "0.1.x-dev",
            "laravel-sso/contracts": "0.1.x-dev"
        }
    }
}
```

Si no tienes `repositories`, crea `"repositories": [ ... ]` y coloca ahí el objeto.
Conserva el resto del archivo. Ajusta sólo la ruta a tu copia; no necesitas poner
`minimum-stability=dev` para todo el proyecto.

## 3. Instala el paquete

```sh
composer require laravel-sso/server:0.1.x-dev laravel-sso/contracts:0.1.x-dev
php artisan sso:install-server
```

## 4. Prepara sus tablas y claves

**Si es la primera vez que instalas Passport en esta aplicación:**

```sh
php artisan migrate --path=vendor/laravel/passport/database/migrations
php artisan passport:keys
```

Si ya usas Passport, conserva sus tablas y claves y omite esos dos comandos.

Para una **primera instalación SSO**:

```sh
php artisan migrate --path=database/migrations/2026_11_13_000001_create_sso_tables.php
php artisan sso:keys
```

Se crea una migración SSO; Passport tiene sus propias migraciones. Las rutas acotadas
evitan ejecutar otras migraciones pendientes de tu aplicación. Si SSO ya estaba instalado,
no repitas esta parte como actualización: ve a [instalaciones previas](INSTALL-SERVER-REFERENCE.md#bases-ya-instaladas).

## 5. Configura el servidor

En `.env`:

```dotenv
APP_URL=https://identidad.test
SSO_SERVER_ENABLED=true
SSO_ISSUER=https://identidad.test
SESSION_DRIVER=file
SESSION_COOKIE=identidad_session
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=null
```

En el array de `config/sso-server.php`, ajusta estas claves, conservando las demás:

```php
'guard' => 'web',
'active_column' => 'is_active', // O el nombre de tu columna booleana existente.
'backchannel_allowed_urls' => [
    'https://ventas.test/sso/backchannel-logout',
],
```

El issuer va sin `/` final. El servidor y Passport usan la misma conexión DB predeterminada.
Tu login existente debe tener la ruta nombrada `login` y autenticar con Laravel Auth.

```sh
php artisan config:clear
php artisan sso:doctor-server
```

**Resultado esperado:** todas las comprobaciones del doctor aparecen como `pass`.

## 6. Registra Ventas como cliente

```sh
php artisan sso:client "Ventas" https://ventas.test/sso/callback --first-party --backchannel=https://ventas.test/sso/backchannel-logout --post-logout=https://ventas.test/
```

Copia los valores `SSO_CLIENT_ID` y `SSO_CLIENT_SECRET` que imprime el comando: los usarás
en Ventas. El secreto sólo se muestra una vez; guárdalo fuera de Git.
`--first-party` se usa aquí porque Ventas es una aplicación tuya.

## 7. Mantén funcionando el logout

En `routes/console.php` de Identidad:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sso:deliver-logouts')->everyMinute()->withoutOverlapping();
Schedule::command('sso:prune-server --limit=1000')->hourly()->withoutOverlapping();
```

En desarrollo, deja `php artisan schedule:work` ejecutándose en otra terminal.
En despliegue, configura el scheduler de Laravel. Si cacheas configuración o usas
procesos persistentes, reconstruye la caché y reinícialos después de cambiar valores.

**Servidor listo:** el doctor pasa, tienes ID/secreto de Ventas y el scheduler está activo.
Continúa con [instalar el cliente](INSTALL-CLIENT.md).

[Opciones avanzadas del servidor](INSTALL-SERVER-REFERENCE.md) ·
[Problemas frecuentes](QUICKSTART.md#si-algo-falla).
