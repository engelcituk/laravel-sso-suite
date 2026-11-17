# Guía rápida: de Laravel limpio a SSO

**Identidad autentica. Ventas conserva sus usuarios y permisos.** Puedes usar Laravel
sin starter kit, Blade, Vue, React o Inertia. El paquete no instala un frontend.

El recorrido principal parte de **dos proyectos Laravel nuevos**: Identidad nace como
servidor central y Ventas como cliente. Las notas para proyectos existentes son opcionales.
Esta guía usa cuentas creadas por cada proyecto y vinculación explícita: instalar/activar
el cliente no crea una cuenta local en una DB vacía. Los pasos 3 y 5 son necesarios
para autorizar la aplicación y resolver la identidad, además de instalar el paquete.

| Dónde ejecutas los comandos | Qué instalas | URL de ejemplo |
| --- | --- | --- |
| Laravel nuevo dedicado a identidad | `laravel-sso/server` | `https://identidad.test` |
| Cada proyecto cliente nuevo | `laravel-sso/client` | `https://ventas.test` |

Requisitos: Laravel 13, PHP 8.3+, Composer 2, DB y Auth con proveedor Eloquent y
SessionGuard. En una instalación limpia, prepara primero su `.env`, APP_KEY y tablas
base como en cualquier Laravel. **No necesitas instalar un starter kit ni agregar
columnas a `users`.** Cada aplicación conserva su DB, APP_KEY y cookie independientes.
Las cuentas las administra tu aplicación; el paquete no crea usuarios automáticamente.

El paquete sigue siendo experimental y no está publicado en Packagist. Para usarlo
ahora, configura Composer con tu copia del repositorio. No hace falta publicar una versión.

## 1. Permite que Composer encuentre los paquetes

En el `composer.json` de Identidad y de Ventas, agrega este objeto a `repositories`.
Si no existe esa propiedad, crea `"repositories": [ ... ]`. Conserva el resto del archivo.

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

Ajusta la ruta a tu checkout. No cambies `minimum-stability` global.

## 2. Instala el servidor central — en Identidad

```sh
composer require laravel-sso/server:0.1.x-dev laravel-sso/contracts:0.1.x-dev
php artisan sso:install-server --configure --migrate --keys
php artisan sso:doctor-server
```

Cuando pregunte **Public issuer URL**, responde `https://identidad.test`.
El comando activa SSO y configura sus variables, prepara sólo las tablas propias/Passport
y genera las claves que falten. No ejecuta migraciones pendientes de tu aplicación.
El doctor debe terminar con todos los controles en `pass`.

**Ya tienes servidor:** si no había login, abre `https://identidad.test/sso/auth/login`.
Aparecerá el formulario opcional del paquete, que valida las cuentas existentes del
Laravel central. No exige `is_active`. Si ya hay una ruta `login`, utiliza esa ruta y
su política, incluido su MFA. No reemplaza sus controladores.

Si tu DB nueva está vacía, todavía debes dar de alta las personas mediante el proceso
propio de tu aplicación. Instalar SSO no inventa cuentas ni contraseñas.

## 3. Registra Ventas — todavía en Identidad

```sh
php artisan sso:client "Ventas" https://ventas.test/sso/callback --first-party --backchannel=https://ventas.test/sso/backchannel-logout --trust-backchannel --post-logout=https://ventas.test/
```

Guarda el **SSO_CLIENT_ID** y **SSO_CLIENT_SECRET** que imprime. El secreto se muestra
una vez y pertenece al backend de Ventas. `--first-party` omite consentimiento porque
es una aplicación tuya. `--trust-backchannel` autoriza exactamente ese destino de logout.

## 4. Instala el cliente — en Ventas

```sh
composer require laravel-sso/client:0.1.x-dev laravel-sso/contracts:0.1.x-dev
php artisan sso:install-client --configure --migrate
```

Responde:

| Pregunta | Respuesta |
| --- | --- |
| Identity server URL | `https://identidad.test` |
| Public callback URL | `https://ventas.test/sso/callback` |
| Client ID | El ID del paso 3 |
| Client secret | El secreto del paso 3; no se muestra al escribirlo |

El instalador configura SSO. El paquete descubre el guard/modelo de Auth y las URLs
estándar del issuer. No necesitas copiar siete endpoints ni programar el callback.

En una aplicación existente, tus rutas `web` con `auth` del guard seleccionado ya
quedan verificadas por SSO. Las rutas convencionales de login, registro y recuperación
se bloquean o redirigen antes de procesar credenciales locales. Para rutas personalizadas,
consulta [la integración detallada](STACK-AGNOSTIC.md#rutas-personalizadas-y-otros-guards).

En una aplicación limpia, crea tu recurso protegido como lo harías normalmente:

```php
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->get('/panel', fn () => ['usuario_local' => auth()->id()]);
```

En `config/sso-client.php`, cambia únicamente el destino si deseas ir a ese panel:

```php
'after_login' => '/panel',
```

## 5. Vincula la misma persona — en Ventas

Ejemplo: **7** es su ID central y **42** es su ID local en Ventas. Ambos usuarios deben
existir; verifica que son la misma persona. No se enlazan automáticamente por email.

```sh
php artisan sso:link-user 42 7
php artisan sso:doctor
```

El vínculo queda en `sso_identities`; no cambia `users`, contraseña, ID, perfil ni permisos.
Si personalizas el identificador central, usa su `getAuthIdentifier()` como segundo argumento.

## 6. Entra y cierra sesión

Abre **`https://ventas.test/sso/login`**. Te lleva a Identidad y vuelve a Ventas como
el usuario local 42. Para agregar otra aplicación, repite los pasos 3–5 con sus propias
credenciales, URLs y vínculo.

Blade:

```blade
<a href="{{ route('sso.login') }}">Entrar</a>
<form method="POST" action="{{ route('sso.logout') }}">
    @csrf
    <button type="submit">Cerrar sesión global</button>
</form>
```

Vue/React: el botón Entrar debe hacer `window.location.assign('/sso/login')`.
El logout usa un formulario POST con CSRF a `/sso/logout`; las redirecciones OIDC
se recorren como navegación del navegador. [Ejemplos y contrato JSON](STACK-AGNOSTIC.md).

## 7. Mantén activo el scheduler de Laravel

```sh
php artisan schedule:list
php artisan schedule:work
```

Hazlo en cada aplicación durante desarrollo. El paquete ya registra entrega de logout
y limpieza: no copies tareas a `routes/console.php`. En despliegue ejecuta el scheduler
normal de Laravel mediante cron o el gestor de procesos del servidor.

Para HTTPS, configura sesiones del servidor (`file`, `database` o Redis), cookies
seguras host-only, nombres de cookie distintos y `SESSION_DOMAIN=null`. El instalador
conserva tu configuración de sesiones y no modifica APP_KEY ni DB. Comprueba esos
requisitos con los doctores. HTTP sólo para desarrollo con `--allow-http` en ambos
instaladores; nunca en producción. No desactives la validación TLS.

**Volver al Auth local de Ventas:** `SSO_CLIENT_ENABLED=false`, reconstruye configuración
y reinicia procesos persistentes. Exige login local nuevo. El paquete no proporciona
login local a un cliente limpio: necesitarás el de tu aplicación si eliges ese modo.
Una caída de Identidad no cambia el modo automáticamente.

Después de configurar o cambiar modo, reconstruye `config:cache` y reinicia procesos
persistentes/OPcache según tu despliegue. El instalador con `--configure` limpia las
cachés de configuración y rutas; reconstruye `route:cache` al terminar la instalación.

## Si algo falla

| Síntoma | Comprobación |
| --- | --- |
| 404 en `/sso/login` | Flag cliente activo y configuración actualizada |
| Cuenta central rechazada | Credenciales del servidor; si configuraste `active_column`, debe existir y ser verdadera |
| Identity has not been linked | Vínculo local, issuer y sujeto central del paso 5 |
| Ruta de login personalizada sigue abierta | Configura `local_auth_routes`/`local_auth_paths` o usa `sso.local` |
| Error de URL permitida tras actualizar | Una configuración antigua se conserva; compara sus claves con la configuración nueva |
| 419 | CSRF y cookies de la aplicación que recibe el POST |
| Certificado/OpenSSL | CA confiable para PHP y navegador; [resolución de problemas](TROUBLESHOOTING.md) |

[Servidor con detalle](INSTALL-SERVER.md) · [Cliente con detalle](INSTALL-CLIENT.md) ·
[Stacks, frontend propio y aplicaciones existentes](STACK-AGNOSTIC.md).
