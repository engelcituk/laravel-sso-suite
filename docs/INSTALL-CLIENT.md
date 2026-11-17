# Instalar el cliente en tu aplicación

**Parte de un proyecto Laravel nuevo que utilizará el servidor central desde el inicio.** Conserva tu modelo,
usuarios, contraseñas, frontend y permisos. El login central inicia una sesión Laravel
con el usuario local vinculado; tus Gates, Policies y Spatie Permission siguen decidiendo
qué puede hacer en esa aplicación.

Greenfield es la prioridad; las notas para aplicaciones existentes son adicionales.
Instalar y activar requiere primero un servidor operativo, registrar este cliente y
configurar la conexión. Además, el flujo predeterminado necesita una cuenta local y su
vínculo: no crea usuarios automáticamente en una DB vacía. El paso 4 explica esa parte.

## 1. Prepara la conexión

Primero [instala el servidor y registra esta aplicación](INSTALL-SERVER.md).
Necesitas el issuer, ID, secreto y callback exactos. Ejemplo:

| Dato | Valor |
| --- | --- |
| Servidor de identidad | `https://identidad.test` |
| Esta aplicación | `https://ventas.test` |
| Callback registrado | `https://ventas.test/sso/callback` |

Requisitos: Laravel 13, PHP 8.3+, Composer 2, Auth con SessionGuard/proveedor Eloquent,
sesiones del servidor y DB base del proyecto preparada. No necesita Passport ni starter kit.
Agrega el repositorio Composer path de [la guía rápida](QUICKSTART.md#1-permite-que-composer-encuentre-los-paquetes).

## 2. Instala y configura

```sh
composer require laravel-sso/client:0.1.x-dev laravel-sso/contracts:0.1.x-dev
php artisan sso:install-client --configure --migrate
```

Responde issuer, callback, ID y secreto. El secreto se pide oculto; si ya existe
`SSO_CLIENT_SECRET`, se conserva ese valor. El comando escribe sólo variables `SSO_*`,
activa el cliente y limpia config/route cache. No cambia APP_KEY, DB, Auth ni rutas de tu app.
Sin `--configure`, únicamente copia archivos faltantes y no activa SSO.

El paquete descubre guard/modelo desde Auth y deriva los endpoints exactos desde el
issuer configurado. La única migración crea `sso_identities`, `sso_local_sessions` y
`sso_logout_receipts`; `--migrate` ejecuta sólo esa migración, sin las pendientes del proyecto.
No modifica `users`, sus hashes, remember_token, IDs ni ACL.

Para desarrollo HTTP agrega `--allow-http` tanto aquí como en el servidor. Se rechaza
en producción. No uses esa opción para resolver un certificado inválido.

## 3. Conserva tus rutas protegidas

**Proyecto existente:** las rutas del grupo `web` que usan `auth` del guard configurado
o `auth:ese_guard` se integran automáticamente. No necesitas reemplazar sus controladores.

**Proyecto nuevo:** define tu recurso con el Auth normal de Laravel:

```php
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->get('/panel', fn () => ['usuario_local' => auth()->id()]);
```

Puedes seleccionar el destino en `config/sso-client.php`:

```php
'after_login' => '/panel',
```

Con SSO activo, antes del recurso se exige sesión SSO vinculada y se verifica el estado
central en cada petición (`strict`). Las rutas convencionales de login, logout, registro,
reset y verificación local se interceptan por nombre/path: GET de navegador redirige a SSO;
POST/JSON devuelve 403 sin procesar credenciales. CSRF sigue activo.

Si tu auth usa rutas/nombres personalizados, middleware propios, varios guards o rutas
fuera de `web`, debes indicar su integración. [Ejemplos y límites](STACK-AGNOSTIC.md#rutas-personalizadas-y-otros-guards).
El paquete no adivina guards API ni sustituye tu autorización.

## 4. Vincula las cuentas existentes

Comprueba que la cuenta local y la central representan a la misma persona. Si su ID en
Ventas es 42 y su identificador central es 7:

```sh
php artisan sso:link-user 42 7
php artisan sso:doctor
```

No se vincula por email. El par `(issuer, subject)` queda en tablas propias y no puede
reclamarse para otra cuenta. Es idempotente para el mismo dueño. Conserva
`'provision' => 'restricted'`, el valor predeterminado, si no quieres altas automáticas.

En una instalación nueva necesitas una cuenta local creada por el proceso de tu aplicación.
Si más adelante eliges altas automáticas, la fábrica pertenece al anfitrión y es una
integración explícita [fuera del flujo de conservación de usuarios](INSTALL-CLIENT-REFERENCE.md#vincular-usuarios-sin-cambiar-sus-datos).
No cambies el modelo a uno especial ni compartas la contraseña central.

## 5. Usa el login/logout desde tu frontend

Abre `https://ventas.test/sso/login`. El paquete hace Code + PKCE, valida el callback
y autentica al usuario local con Laravel. Otra app ya conectada reutiliza la sesión
central. Las cuentas y permisos locales siguen siendo independientes.

En Blade:

```blade
<a href="{{ route('sso.login') }}">Entrar</a>
<form method="POST" action="{{ route('sso.logout') }}">
    @csrf
    <button type="submit">Cerrar sesión global</button>
</form>
```

En Vue/React usa navegación completa a `/sso/login` y formulario POST con CSRF para
logout. Inertia recibe `409`/`X-Inertia-Location` al interceptarse el acceso, para salir
de la visita XHR. No instala el paquete Inertia. Para un botón explícito de login usa
un enlace HTML normal. [Frontend y respuestas API](STACK-AGNOSTIC.md).

## 6. Opera ambos modos

| Flag | Comportamiento |
| --- | --- |
| `SSO_CLIENT_ENABLED=true` | Login central, recursos integrados requieren SSO; no acepta un login local previo como SSO |
| `SSO_CLIENT_ENABLED=false` | Tu Auth local continúa; las rutas SSO devuelven 404 y no se consulta al servidor |
| Cambio de SSO a local | Invalida el origen SSO y exige autenticarte localmente otra vez |
| Servidor central caído | Rechaza el acceso SSO; no cambia a local automáticamente |

Un cliente limpio sin login local puede funcionar sólo con SSO. Si después quieres
modo local, proporciona el login de tu aplicación. El paquete no cambia sus contraseñas.

Ejecuta `php artisan schedule:list`: la limpieza horaria ya estará registrada cuando
SSO esté activo. Mantén funcionando el scheduler normal de Laravel. Si ya programas
esa tarea tú mismo, usa `'schedule' => false` para evitar duplicarla.

Usa sesiones `file`, `database` o Redis y cookies seguras host-only en HTTPS. Las
aplicaciones conservan APP_KEY, DB y nombres de cookie independientes. Después de
configurar o cambiar modo, reconstruye config cache y reinicia procesos persistentes.
Una caché de rutas del cliente creada con SSO apagado también sirve al activarlo.

Las configuraciones previamente copiadas se conservan: compara opciones nuevas al
actualizar. No reapliques la migración inicial ni uses `migrate:fresh` contra tus datos.
[Referencia avanzada](INSTALL-CLIENT-REFERENCE.md) · [Guía completa por stack](STACK-AGNOSTIC.md).
