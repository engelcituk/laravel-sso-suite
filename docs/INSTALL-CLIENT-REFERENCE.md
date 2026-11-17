# Referencia avanzada del cliente

Para instalarlo paso a paso, usa [Instalar el cliente](INSTALL-CLIENT.md) o la
[guía rápida completa](QUICKSTART.md). Las fábricas de usuarios de esta referencia son
una opción avanzada; la guía rápida usa `provision=greenfield` con altas y vínculos
automáticos sólo de cuentas nuevas. Las existentes se conservan. `--migrate` ejecuta sólo la migración cliente.
[Integración automática y contratos por stack](STACK-AGNOSTIC.md).

El cliente extiende Laravel Auth. Conserva tu modelo, tabla `users`, contraseñas,
`remember_token`, relaciones y ACL. No instala Passport ni modifica ese esquema.

Agrega el repositorio path de [la guía del servidor](INSTALL-SERVER.md), con versiones
`laravel-sso/client: 0.1.x-dev` y `laravel-sso/contracts: 0.1.x-dev`:

```sh
composer require laravel-sso/client:0.1.x-dev laravel-sso/contracts:0.1.x-dev
php artisan sso:install-client --configure --migrate
```

La única migración del cliente crea tres tablas propias: `sso_identities`,
`sso_local_sessions` y `sso_logout_receipts`. El instalador conserva archivos y el
timestamp de una copia existente. No borres columnas de tu tabla de usuarios ni uses
`SsoOnly` en un modelo que deba seguir admitiendo contraseña local.

## Integración de los dos modos

```dotenv
SSO_CLIENT_ENABLED=false
```

Es el valor predeterminado. Funciona el Auth nativo; no se consulta al OP ni se necesitan
tablas SSO para entrar o usar recursos locales. Las rutas `/sso/*` del paquete responden
404. `sso:doctor` informa que está desactivado sin exigir configuración OIDC.
Instalar el paquete no sustituye tus guards, controladores o rutas de autenticación.

Por defecto se integran las rutas convencionales dentro de `web`: `auth` del guard
seleccionado exige SSO con política strict, y login/registro/reset/logout se interceptan
por patrones configurables. Para integración manual (`integrate_auth=false`) o rutas
personalizadas, aplica estos middleware:

```php
Route::middleware('sso.local')->group(function () {
    // Tus rutas/controladores de login, registro, recuperación y logout nativos.
    // Aplica sso.local antes del middleware guest de esas rutas.
});

Route::middleware(['auth', 'sso.session'])->group(function () {
    Route::get('/dashboard', fn () => response()->json(['local_id' => auth()->id()]));
});
```

Con SSO activo, `sso.local` redirige GET de navegador a `sso.login` y rechaza POST/JSON
con 403 antes de procesar credenciales. Conserva CSRF. Los nombres/paths convencionales
de Laravel/Fortify están incluidos en la plantilla. Rutas personalizadas requieren sus
patrones o middleware explícito; revisa cualquier middleware Auth propio o varios guards.

`sso.session` exige una sesión OIDC vinculada al usuario actual; una sesión nativa
anterior no se convierte en SSO. Al desactivar SSO, una sesión de origen SSO se invalida
y exige login local nuevo. El cierre SSO conserva password y remember_token mediante
`logoutCurrentDevice()`; el logout nativo mantiene el comportamiento de tu aplicación.

Configura el proveedor Eloquent y `sso-client.user_model` con el mismo modelo real,
`sso-client.guard` con el SessionGuard correcto y `local_login_url` con tu login nativo.
En integración manual, la redirección de invitados debe seguir el modo, por ejemplo en
bootstrap. La integración automática de rutas convencionales ya resuelve esa redirección:

```php
$middleware->redirectGuestsTo(fn () => config('sso-client.enabled') ? '/sso/login' : '/login');
```

## Activar OIDC

```dotenv
SSO_CLIENT_ENABLED=true
SSO_ISSUER=https://identity.example.org
SSO_CLIENT_ID=identificador-registrado
SSO_CLIENT_SECRET=secreto-del-cliente
SSO_REDIRECT_URI=https://one.example.org/sso/callback
SSO_SESSION_STATUS_URL=https://identity.example.org/oidc/session
```

La configuración nueva deriva `sso-client.allowed_urls` para discovery, authorize, token,
jwks, userinfo, session y logout del issuer mediante URLs exactas. Una configuración
antigua o endpoints distintos requieren mantener esa lista explícitamente.
El transporte servidor-a-servidor no sigue
redirecciones. No se toma un proveedor de parámetros del visitante. Usa sesiones de
servidor (`database`, `file` o Redis): con SSO activo se rechaza el driver cookie.
Los tokens sólo se conservan dentro de la sesión del servidor.

El callback valida state/nonce, firma, issuer, audiencia y tiempos; comprueba `iss` si el
OP lo anuncia y `at_hash` si aparece. UserInfo debe tener el mismo `sub` que el ID Token.
Un fallo impide el login. No sincroniza el perfil de usuarios ya vinculados.
Puedes fijar `max_age` (segundos, incluido 0); no se acepta desde el visitante.

## Vincular usuarios sin cambiar sus datos

La plantilla nueva usa `provision=greenfield`: una fábrica incluida crea y vincula la
cuenta al primer login SSO validado, sin código del anfitrión. Requiere el esquema Laravel
convencional con email único; una colisión de email se rechaza, nunca se adopta otra cuenta.
[Contrato y pruebas](GREENFIELD-PROVISIONING.md). Las configuraciones existentes se conservan.

Con `provision=restricted`, un operador vincula una cuenta local existente con
el `iss/sub` verificado del OP. No se buscan coincidencias por correo ni se reasignan
identidades ya vinculadas a otro propietario.

```sh
php artisan sso:link-user 42 sujeto-central --issuer=https://identity.example.org
```

También puedes usar `app(\LaravelSso\Client\IdentityLinks::class)->link($user, $issuer, $subject)`
desde un proceso autorizado. La relación se guarda en `sso_identities`, con índice único
sensible a mayúsculas `(issuer, subject)`. Conserva la clase concreta del modelo y su ID
como texto (hasta 191 bytes); no exige morph map ni columnas en `users`. El modelo debe
usar la misma conexión que las tablas SSO para mantener atomicidad.

Para altas con un modelo/esquema personalizado, configura `provision=automatic` y una fábrica propia en
`create_user`. Preferir una clase invocable permite usar `config:cache`:

```php
// config/sso-client.php
'provision' => 'automatic',
'create_user' => App\Auth\CreateSsoUser::class,
```

```php
namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LaravelSso\Contracts\Identity;

final class CreateSsoUser
{
    public function __invoke(Identity $identity): User
    {
        // El anfitrión decide sus campos obligatorios y el tratamiento de email duplicado.
        return User::create([
            'name' => is_string($identity->attributes['name'] ?? null) ? $identity->attributes['name'] : null,
            'email' => is_string($identity->attributes['email'] ?? null) ? $identity->attributes['email'] : null,
            'password' => Hash::make(Str::random(64)),
        ]);
    }
}
```

La fábrica sólo se llama para un alta nueva, dentro de la transacción. Devuelve un usuario
persistido del modelo configurado, sin otorgar privilegios. El secreto aleatorio del ejemplo
no es la contraseña central y no se aplica a usuarios existentes. Si después quieres login
local para esos usuarios nuevos, define un proceso de establecimiento/recuperación de
contraseña del anfitrión. No inventes una contraseña compartida. `automatic` sin fábrica
rechaza el alta. Una cuenta local con el mismo email no se enlaza automáticamente.

`allowed` puede rechazar el login; el alta y la vinculación se revierten en ese caso.
Aplica además Gates/Policies en cada recurso para mantener la autorización persistente.
Los usuarios ya vinculados conservan nombre, email, password, remember_token, ID y ACL.

## Operar y actualizar

Con integración automática, `auth` del guard seleccionado en `web` ya verifica origen
y revocación mediante strict. Con integración manual, usa `auth` junto con `sso.session`
en todos los recursos que deban seguir el modo. Las operaciones sensibles usan
`sso.session:strict`; el OP caído deniega el acceso, nunca habilita Auth local automáticamente.
El logout activo envía POST a `route('sso.logout')` con `@csrf`; en modo local usa tu logout.
Back-channel se autentica por firma/audiencia/tiempos y recibo de un uso, sin web/CSRF.

Después de cambiar el entorno, reconstruye la configuración cacheada en cada instancia
y recarga procesos persistentes según tu despliegue. Las rutas SSO siempre se registran
y se protegen mediante `sso.enabled`: una caché creada con el flag apagado no impide
activarlas. El cambio de modo es una decisión del operador, no un failover.

Ejecuta `sso:doctor --offline` y luego `sso:doctor --json` para añadir discovery/JWKS reales.
La limpieza `sso:prune-client --limit=1000` se registra automáticamente cada hora cuando
está activo; mantén el scheduler de Laravel. Para tareas propias usa `schedule=false`
y evita duplicarlas. Elimina índices/recibos caducados, nunca
usuarios, permisos o vinculaciones. La sesión local está limitada por `local_session_ttl`
y la duración del token. Ver [retención](DATABASE-CONCURRENCY.md).

**Bases de desarrollo ya instaladas:** los cambios están consolidados en la migración
inicial. Laravel no vuelve a ejecutar una migración registrada; copiar el archivo no
actualiza esas bases. Revisa y aplica el cambio de las tablas propias (`sso_identities`
y columnas de propietario en `sso_local_sessions`) mediante un procedimiento de esquema
controlado y respaldado, y vincula los usuarios existentes con `iss/sub` verificados.
No ejecutes `migrate:fresh` sobre una aplicación con datos. Las antiguas columnas SSO de
tu modelo pueden quedar sin uso; el paquete no las borra ni rellena contraseñas ausentes.
Los ejemplos actualizados describen instalaciones nuevas; no se han reseteado tus bases.

[Contrato, resultados y límites del modo opcional](OPTIONAL-AUTH.md).
