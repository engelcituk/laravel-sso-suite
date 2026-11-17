# Instalar el cliente en tu aplicación

Aquí conectas tu aplicación **Ventas**, `https://ventas.test`, al servidor de identidad,
`https://identidad.test`. Necesitas Laravel 13, PHP 8.3+, Composer 2, tu Auth actual y
usuarios existentes. Sustituye los dominios por los tuyos, con HTTPS válido.

Primero [registra Ventas en el servidor](INSTALL-SERVER.md#6-registra-ventas-como-cliente)
y ten a mano su `SSO_CLIENT_ID` y `SSO_CLIENT_SECRET`.
**Todos los comandos siguientes se ejecutan dentro de Ventas.** El cliente no instala Passport.

Tu modelo `User`, contraseñas y permisos se conservan. La vinculación se guarda en tablas
SSO y no crea usuarios automáticamente.

## 1. Prepara Composer

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

## 2. Instala el paquete y sus tablas

Con el repositorio Composer del paso 1 ya agregado:

```sh
composer require laravel-sso/client:0.1.x-dev laravel-sso/contracts:0.1.x-dev
php artisan sso:install-client
php artisan migrate --path=database/migrations/2026_11_13_000002_create_sso_local_sessions.php
```

El cliente crea tres tablas propias mediante una sola migración. No necesita Passport.
Este comando de migración es para una primera instalación SSO.

<a id="activar-oidc"></a>

## 3. Configura la conexión con Identidad

En `.env` de Ventas:

```dotenv
APP_URL=https://ventas.test
SSO_CLIENT_ENABLED=true
SSO_ISSUER=https://identidad.test
SSO_CLIENT_ID=PEGA_EL_ID_GENERADO_EN_EL_SERVIDOR
SSO_CLIENT_SECRET=PEGA_EL_SECRETO_GENERADO_EN_EL_SERVIDOR
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

<a id="integración-de-los-dos-modos"></a>

## 4. Protege tu panel y tus rutas de login

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

<a id="vincular-usuarios-sin-cambiar-sus-datos"></a>

## 5. Vincula una cuenta existente y comprueba

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

## 6. Entra y cierra sesión

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

Para otra aplicación, repite el registro del cliente en el servidor con su propio dominio y estos pasos
en esa aplicación. Usa su propio ID/secreto, cookie y vínculo de usuario.

<a id="operar-y-actualizar"></a>

## 7. Programa la limpieza y elige el modo

En `routes/console.php` de Ventas:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sso:prune-client --limit=1000')->hourly()->withoutOverlapping();
```

En desarrollo, deja `php artisan schedule:work` en otra terminal; en despliegue configura
el scheduler de Laravel. Después de cambios, reconstruye la caché de configuración y
reinicia los procesos persistentes si los usas.

- `SSO_CLIENT_ENABLED=true`: entrada por Identidad; una caída del servidor deniega acceso.
- `SSO_CLIENT_ENABLED=false`: Auth local; `/sso/*` devuelve 404 y exige nuevo login local.

El flag funciona sobre las rutas que integraste en el paso 4. No se cambia automáticamente.
Para volver a local, cambia el flag y ejecuta `php artisan config:clear` en desarrollo,
o reconstruye `config:cache` y reinicia los procesos persistentes en despliegue.
Los botones SSO del ejemplo son para el modo activo; al volver a local usa tus botones nativos.

[Opciones avanzadas del cliente](INSTALL-CLIENT-REFERENCE.md) ·
[Problemas frecuentes](QUICKSTART.md#si-algo-falla) · [Guía completa](QUICKSTART.md).
