# Guía rápida: de Laravel limpio a SSO

**El servidor de identidad autentica. El cliente conserva sus usuarios y permisos.** Puedes usar Laravel
sin starter kit, Blade, Vue, React o Inertia. El paquete no instala un frontend.

El recorrido principal parte de **dos proyectos Laravel nuevos**: Identidad nace como
servidor central y el segundo proyecto actúa como cliente. Las notas para proyectos existentes son opcionales.
Los paquetes agregan capacidades a esos proyectos; su CRUD y frontend siguen siendo
tuyos. Puedes [gestionar aplicaciones desde tus controladores](CLIENT-REGISTRATION-SERVICE.md)
con el mismo servicio que usa Artisan, sin instalar un panel administrativo.
La cuenta central la administra Identidad. **El cliente puede comenzar con `users` vacía:**
el primer login SSO válido crea y vincula automáticamente su cuenta local. No necesitas
una fábrica propia ni ejecutar `sso:link-user` para una persona nueva.

| Dónde ejecutas los comandos | Qué instalas | URL de ejemplo |
| --- | --- | --- |
| Laravel nuevo dedicado a identidad | `laravel-sso/server` | `https://identidad.test` |
| Cada proyecto cliente nuevo | `laravel-sso/client` | `https://app.test` |

Estos dominios son ejemplos. Configura su resolución y certificados HTTPS para el
entorno local, o sustitúyelos por tus dominios en todos los comandos y callbacks.

Requisitos: Laravel 13, PHP 8.3+, Composer 2, DB y Auth con proveedor Eloquent y
SessionGuard. En una instalación limpia, prepara primero su `.env`, APP_KEY y tablas
base como en cualquier Laravel. **No necesitas instalar un starter kit ni agregar
columnas a `users`.** Cada aplicación conserva su DB, APP_KEY y cookie independientes.
Identidad necesita cuentas centrales con email válido; puedes crearlas con `sso:user`.
El cliente usa el esquema Laravel convencional: `name`, `email` único y `password`.

El paquete sigue siendo experimental y no está publicado en Packagist. Composer puede
descargarlo desde GitHub mediante el origen remoto siguiente, sin una copia local de la suite.

## 1. Permite que Composer encuentre los paquetes

En la terminal de **cada proyecto Laravel**, ejecuta una vez:

```sh
composer config repositories.laravel-sso composer https://raw.githubusercontent.com/engelcituk/laravel-sso-suite/composer
```

El comando agrega un repositorio remoto a su `composer.json`. Tus proyectos pueden
estar en cualquier carpeta, incluidos sitios de Herd bajo `www`. No cambies
`minimum-stability` global. Si configuraste antes un origen `path` para estos paquetes,
elimínalo. [Distribución, lockfile y actualizaciones](GITHUB-DISTRIBUTION.md).

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

Si tu DB nueva está vacía, el paso 3 permite crear las personas desde el servidor.
No se crean cuentas demo al instalar. Conserva las migraciones base de Laravel, incluida
`password_reset_tokens`, para utilizar la recuperación central de contraseña.

## 3. Registra la aplicación cliente — en Identidad

```sh
php artisan sso:client "Aplicación cliente" https://app.test/sso/callback --first-party --backchannel=https://app.test/sso/backchannel-logout --trust-backchannel --post-logout=https://app.test/
```

Guarda el **SSO_CLIENT_ID** y **SSO_CLIENT_SECRET** que imprime. El secreto se muestra
una vez y pertenece al backend del cliente. `--first-party` omite consentimiento porque
es una aplicación tuya. `--trust-backchannel` autoriza exactamente ese destino de logout.

Las aplicaciones nuevas sólo permiten cuentas centrales asignadas. Para crear y asignar
una persona desde Identidad, sustituye `ID_DEL_CLIENTE` por el `SSO_CLIENT_ID` anterior:

```sh
php artisan sso:user persona@example.test --name="Usuario de ejemplo" --client=ID_DEL_CLIENTE
```

Pide una contraseña inicial en un prompt oculto. También puedes añadir `--send-reset`
para enviar un enlace de establecimiento de contraseña si ya configuraste correo.
Si la cuenta central ya existe con ID 7, asígnala sin volver a crearla:

```sh
php artisan sso:client-user ID_DEL_CLIENTE 7
```

El registro público queda cerrado por defecto. Login y recuperación están en
`/sso/auth/login` y `/sso/auth/forgot-password`. [Flujo completo, correo y JSON](CENTRAL-ACCOUNTS.md).

## 4. Instala el paquete en el proyecto cliente

```sh
composer require laravel-sso/client:0.1.x-dev laravel-sso/contracts:0.1.x-dev
php artisan sso:install-client --configure --migrate
```

Responde:

| Pregunta | Respuesta |
| --- | --- |
| Identity server URL | `https://identidad.test` |
| Public callback URL | `https://app.test/sso/callback` |
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

## 5. Comprueba la conexión del cliente

```sh
php artisan sso:doctor
```

La configuración nueva usa `provision=greenfield`. Puedes dejar `users` vacía: el callback
creará una cuenta local y el vínculo `(issuer, subject)` juntos, después de validar OIDC.
No necesitas crearla antes ni asignar permisos para que exista. Sus permisos los decide el cliente.

Se guarda sólo nombre, email y verificación de email cuando el servidor envía `true`.
La columna `password` estándar recibe un hash de un valor aleatorio que se descarta;
no se copia la contraseña central ni se entrega una contraseña local. Las cuentas y
contraseñas existentes no se modifican. Un login posterior no sincroniza el perfil.

**Si el correo ya existe sin vínculo**, el login se rechaza. Sólo después de verificar
que es la misma persona puedes usar `sso:link-user ID_LOCAL SUJETO_CENTRAL`.
No se vincula por coincidencia de email. Si instalaste una configuración antigua con
`restricted`, se conserva: selecciona explícitamente `greenfield` para este flujo.

## 6. Entra y cierra sesión

Abre **`https://app.test/sso/login`**. Te lleva a Identidad y vuelve a la aplicación cliente como
su usuario local recién creado. Repite el login: debe conservar el mismo ID local y
una sola vinculación. Para agregar otra aplicación, repite los pasos 3–5 con sus propias
credenciales y URLs; creará su cuenta local al primer acceso.

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

**Volver al Auth local del cliente:** `SSO_CLIENT_ENABLED=false`, reconstruye configuración
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
| Identity has not been linked | Configuración antigua en restricted; elegir greenfield para altas nuevas o vincular una cuenta existente verificada |
| Primer login rechazado en greenfield | Email central válido, esquema convencional con email único y ausencia de una cuenta local con ese email sin vincular |
| Ruta de login personalizada sigue abierta | Configura `local_auth_routes`/`local_auth_paths` o usa `sso.local` |
| Error de URL permitida tras actualizar | Una configuración antigua se conserva; compara sus claves con la configuración nueva |
| 419 | CSRF y cookies de la aplicación que recibe el POST |
| Certificado/OpenSSL | CA confiable para PHP y navegador; [resolución de problemas](TROUBLESHOOTING.md) |

[Servidor con detalle](INSTALL-SERVER.md) · [Cliente con detalle](INSTALL-CLIENT.md) ·
[Stacks, frontend propio y aplicaciones existentes](STACK-AGNOSTIC.md).
