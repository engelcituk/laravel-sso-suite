# Guía rápida: servidor y cliente

**Primero configura el servidor de identidad. Después conecta tu aplicación cliente.**

| Aplicación | Ejemplo | Qué instalas |
| --- | --- | --- |
| Servidor de identidad | `https://identidad.test` | `laravel-sso/server` |
| Tu aplicación, por ejemplo Ventas | `https://ventas.test` | `laravel-sso/client` |

El usuario entra a Ventas, se autentica en Identidad y vuelve a Ventas con su cuenta
local vinculada. Sus permisos siguen siendo los de Ventas.

Necesitas **Laravel 13, PHP 8.3+ y Composer 2**, dos aplicaciones con usuarios existentes
y login funcionando. Sustituye los dos dominios por los tuyos, con HTTPS válido tanto
para el navegador como para PHP. El servidor usa una columna existente para saber si
una cuenta está activa: `is_active` por defecto. Si no tienes una equivalente, consulta
el requisito del [paso 1 del servidor](INSTALL-SERVER.md#1-comprueba-tu-login-actual).

El paquete es experimental y aún no está en Packagist. Estos pasos usan tu copia del
repositorio, conservan `users` y sus contraseñas y no crean usuarios automáticamente.
Cada aplicación conserva su propia DB y APP_KEY. `SESSION_DRIVER=file` es la opción
del ejemplo para una instancia; puedes conservar tu driver de servidor actual si ya funciona.

## A. Prepara Composer — una vez en cada aplicación

Descarga este repositorio, por ejemplo en `C:/laragon/www/laravel-sso`.
En el `composer.json` de **Identidad** y de **Ventas**, agrega este elemento a `repositories`:

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

## B. Configura el servidor — dentro de Identidad

### 1. Instala el paquete

```sh
composer require laravel-sso/server:0.1.x-dev laravel-sso/contracts:0.1.x-dev
php artisan sso:install-server
```

### 2. Prepara sus tablas y claves

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

### 3. Configura el servidor

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

### 4. Registra Ventas como cliente

```sh
php artisan sso:client "Ventas" https://ventas.test/sso/callback --first-party --backchannel=https://ventas.test/sso/backchannel-logout --post-logout=https://ventas.test/
```

Copia los valores `SSO_CLIENT_ID` y `SSO_CLIENT_SECRET` que imprime el comando: los usarás
en Ventas. El secreto sólo se muestra una vez; guárdalo fuera de Git.
`--first-party` se usa aquí porque Ventas es una aplicación tuya.

## C. Configura el cliente — dentro de Ventas

### 5. Instala el paquete y sus tablas

Con el repositorio Composer del paso A ya agregado:

```sh
composer require laravel-sso/client:0.1.x-dev laravel-sso/contracts:0.1.x-dev
php artisan sso:install-client
php artisan migrate --path=database/migrations/2026_11_13_000002_create_sso_local_sessions.php
```

El cliente crea tres tablas propias mediante una sola migración. No necesita Passport.
Este comando de migración es para una primera instalación SSO.

### 6. Configura la conexión con Identidad

En `.env` de Ventas:

```dotenv
APP_URL=https://ventas.test
SSO_CLIENT_ENABLED=true
SSO_ISSUER=https://identidad.test
SSO_CLIENT_ID=PEGA_EL_ID_DEL_PASO_4
SSO_CLIENT_SECRET=PEGA_EL_SECRETO_DEL_PASO_4
SSO_REDIRECT_URI=https://ventas.test/sso/callback
SSO_SESSION_STATUS_URL=https://identidad.test/oidc/session
SESSION_DRIVER=file
SESSION_COOKIE=ventas_session
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=null
```

En el array de `config/sso-client.php`, ajusta estas claves:

```php
'user_model' => App\Models\User::class,
'guard' => 'web',
'provision' => 'restricted',
'local_login_url' => '/login',
'after_login' => '/panel',
'allowed_urls' => [
    'https://identidad.test/.well-known/openid-configuration',
    'https://identidad.test/oidc/authorize',
    'https://identidad.test/oidc/token',
    'https://identidad.test/oidc/jwks',
    'https://identidad.test/oidc/userinfo',
    'https://identidad.test/oidc/session',
    'https://identidad.test/oidc/logout',
],
```

`user_model` y `guard` deben coincidir con tu configuración actual de Auth.
Conserva las APP_KEY existentes e independientes; no compartas cookies entre aplicaciones.

### 7. Protege tu panel y tus rutas de login

En `routes/web.php`, agrega una ruta de prueba:

```php
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'sso.session:strict'])->get('/panel', function () {
    return ['usuario_local' => auth()->id()];
});
```

Si ya tienes `/panel`, agrega esos middleware a su ruta/grupo en vez de duplicarla.
Conserva tus Gates/Policies en los recursos que los usan.

Agrega `sso.local` a tus rutas nativas de login, registro, recuperación y logout,
después de `web` y antes de `guest`. Si se cargan desde `routes/auth.php`, sustituye
el `require` actual por:

```php
Route::middleware('sso.local')->group(function () {
    require __DIR__.'/auth.php';
});
```

Si usas Fortify u otro sistema que registra sus propias rutas, aplica `sso.local` a
esas rutas desde su configuración; no declares un segundo login. El flag no las intercepta solo.

Dentro del callback `withMiddleware` existente de `bootstrap/app.php`, agrega:

```php
$middleware->redirectGuestsTo(fn () => config('sso-client.enabled') ? '/sso/login' : '/login');
```

### 8. Vincula una cuenta existente y comprueba

Ejemplo: la persona tiene ID **7 en Identidad** e ID **42 en Ventas**. Verifica que son
la misma persona; no basta con que coincida el correo. En el modelo predeterminado,
el `sub` central es su ID. Si personalizaste el identificador, usa el valor de
`getAuthIdentifier()` del usuario central.

En Ventas:

```sh
php artisan config:clear
php artisan sso:link-user 42 7
php artisan sso:doctor
```

**Resultado esperado:** vínculo creado y todas las comprobaciones en `pass`.
El vínculo se guarda en `sso_identities`; el usuario y su contraseña permanecen iguales.

## D. Úsalo

Abre **`https://ventas.test/sso/login`**. Entrarás por Identidad y volverás a `/panel`
de Ventas, que mostrará el ID local **42**. No necesitas programar el callback.

En una vista Blade de Ventas, puedes usar:

```blade
<a href="{{ route('sso.login') }}">Entrar con SSO</a>

<form method="POST" action="{{ route('sso.logout') }}">
    @csrf
    <button type="submit">Cerrar sesión global</button>
</form>
```

Para otra aplicación, repite el registro del paso 4 con su propio dominio y los pasos
5–8 en esa aplicación. Usa su propio ID/secreto, cookie y vínculo de usuario.

## E. Deja funcionando el cierre de sesión

En `routes/console.php` de **Identidad**:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sso:deliver-logouts')->everyMinute()->withoutOverlapping();
Schedule::command('sso:prune-server --limit=1000')->hourly()->withoutOverlapping();
```

En `routes/console.php` de **Ventas**:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sso:prune-client --limit=1000')->hourly()->withoutOverlapping();
```

En desarrollo, ejecuta `php artisan schedule:work` en una terminal por aplicación.
En despliegue usa el scheduler de Laravel de tu servidor. Tras cambiar configuración
cacheada, reconstruye `config:cache` y reinicia los procesos persistentes de cada app.

**Para volver al login local de Ventas:** cambia `SSO_CLIENT_ENABLED=false`, limpia o
reconstruye la configuración y vuelve a autenticarte localmente. Las rutas `/sso/*`
quedarán desactivadas. Una caída de Identidad no cambia el modo automáticamente.
Al volver a local, usa tus botones nativos de login/logout; los botones SSO mostrados
arriba son para el modo activo.

## Si algo falla

| Lo que ves | Qué revisar |
| --- | --- |
| `/sso/login` devuelve 404 | `SSO_CLIENT_ENABLED=true` y configuración actualizada en Ventas |
| Cuenta central rechazada | La columna de cuenta activa del servidor y el guard configurado |
| “Identity has not been linked” | El paso 8: usuario local, issuer y `sub` correctos |
| Error de URL permitida | Los siete endpoints exactos en `allowed_urls` y el back-channel en el servidor |
| Error TLS/certificado | Certificado y CA confiables para navegador y PHP; no desactives la verificación |
| Error al crear claves en Windows | [Configuración OpenSSL](TROUBLESHOOTING.md) |

Guías por rol: [servidor](INSTALL-SERVER.md) · [cliente](INSTALL-CLIENT.md).
Opciones avanzadas: [servidor](INSTALL-SERVER-REFERENCE.md) · [cliente](INSTALL-CLIENT-REFERENCE.md).
