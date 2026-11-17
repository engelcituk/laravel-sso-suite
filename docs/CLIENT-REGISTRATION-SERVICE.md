# Gestionar clientes desde tu aplicación o Artisan

Laravel SSO extiende tu aplicación Laravel con servicios de integración. Tu aplicación
sigue siendo dueña de sus modelos, CRUD, permisos administrativos, rutas y frontend.
Los paquetes `server` y `client` identifican el papel SSO que agregan a ese proyecto;
no son aplicaciones independientes ni un panel obligatorio.

La creación de sistemas está disponible mediante **`RegistersClients`**, resuelto por
el contenedor a **`Clients`**. El comando `sso:client` usa ese mismo contrato. Tu CRUD
puede invocarlo directamente, sin ejecutar Artisan ni duplicar la validación OAuth/SSO.
Para listar, consultar, editar y revocar, inyecta **`ManagesClients`**, también resuelto
a `Clients`. No se instala un backend administrativo: tú defines sus rutas y permisos.

## Listar, consultar, editar y revocar

```php
use LaravelSso\Server\ClientChanges;
use LaravelSso\Server\Contracts\ManagesClients;

$clients = app(ManagesClients::class);
$page = $clients->listing(status: 'active', perPage: 20, page: 1);
$client = $clients->find($id);
$updated = $clients->update($id, new ClientChanges(
    name: 'Aplicación cliente',
    redirectUri: 'https://app.test/sso/callback',
    restrictUsers: true,
));
$revoked = $clients->revoke($id);
```

`listing()` devuelve `LengthAwarePaginator<int, ClientDetails>`, ordenado por ID estable.
Sus enlaces conservan el filtro y el tamaño de página.
`status` admite `active` (predeterminado), `revoked` o `all`; `perPage` admite 1–100 y
`page` 1–1 000 000. Incluye sólo clientes registrados en ambas tablas OAuth/SSO, sin
mostrar ni administrar otros clientes Passport del anfitrión. `find()` incluye revocados.
Los resultados contienen ID, nombre, URLs, tipo público, políticas y estado de revocación;
no contienen secretos, hashes, tokens ni usuarios. Los nombres y URLs sí son datos
administrativos: protege también los endpoints de consulta.

`ClientChanges` es una edición parcial: `null` conserva el campo y `false` es un cambio
explícito. Admite `name`, `redirectUri`, `firstParty`, `requirePkce`, `restrictUsers`,
`backchannelLogoutUri` y `postLogoutRedirectUri`. Para quitar una URL opcional de logout,
envía `''`; para conservarla, omítela. Si cambias `redirectUri` o `postLogoutRedirectUri`,
reemplaza la lista correspondiente por esa URL; si los omites, conserva la lista completa.
No permite cambiar el ID, convertir el tipo público/confidencial, rotar el secreto ni
reactivar un cliente revocado. Sigue validando HTTPS, PKCE y la allowlist del operador.
Para una nueva URL back-channel, el operador debe confiarla primero en la configuración
y reconstruir su caché; edición y revocación nunca escriben `.env`.

**Efecto de guardar:** cualquier edición efectiva, incluso de nombre, invalida todos
los códigos pendientes, access tokens y refresh tokens de esa aplicación. Los usuarios
pueden iniciar un flujo SSO nuevo con la configuración actual. Guardar los mismos valores
no invalida tokens ni genera auditoría. Cambiar `restrictUsers` no agrega ni elimina
asignaciones: al activarlo sólo entran las personas ya asignadas; al desactivarlo entran
todas las cuentas centrales elegibles. Restringe esa opción a operadores autorizados.

**Efecto de revocar:** es definitivo e idempotente; bloquea autorización/canje e invalida
los mismos códigos/tokens. Conserva la ficha y sus asignaciones para inspección; no borra
ni modifica cuentas, contraseñas, dispositivos o sesiones centrales. Las otras aplicaciones
siguen funcionando. No envía back-channel ni HTTP; el cliente con comprobación estricta
deniega la siguiente petición al comprobar el token. Un cliente que conserva una sesión
local sin comprobar al servidor requiere el cierre local de tu aplicación o su caducidad:
revocar el cliente no puede borrar remotamente una cookie ni un JWT ya recibido.

Las operaciones mutantes son transaccionales y usan el mismo bloqueo por cliente que
la emisión de códigos/tokens, también para aplicaciones que permiten todos los usuarios.
Se revalida el cliente tras obtener el bloqueo; un consentimiento pendiente no emite un
código hacia una URL retirada. PostgreSQL REPEATABLE READ fuerza reintento si el snapshot
precede a un canje concurrente. Los eventos `client.updated` y `client.revoked` se emiten
después del commit, con la referencia del cliente protegida por HMAC. Si tu aplicación
abre una transacción externa, debe reintentar esa transacción completa ante un deadlock.

ID inválido, inexistente o ajeno al registro SSO lanza `ModelNotFoundException` (404 en
un controlador Laravel normal). Datos inválidos lanzan `ValidationException` (422 JSON).
Editar un revocado lanza `RuntimeException`; el anfitrión puede traducirla a 409.

### Tu CRUD: endpoints propios y autorizados

Añade estos métodos al controlador de alta mostrado más abajo. Importa `ClientChanges`,
`ManagesClients` y `LengthAwarePaginator` además de los imports de ese ejemplo:

```php
use Illuminate\Pagination\LengthAwarePaginator;
use LaravelSso\Server\ClientChanges;
use LaravelSso\Server\Contracts\ManagesClients;

// Métodos dentro de SsoClientController:
public function index(Request $request, ManagesClients $clients): LengthAwarePaginator
{
    Gate::authorize('manage-sso-clients');
    $input = $request->validate([
        'status' => ['sometimes', 'in:active,revoked,all'],
        'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
    ]);

    return $clients->listing($input['status'] ?? 'active', (int) ($input['per_page'] ?? 20), (int) ($input['page'] ?? 1));
}

public function show(string $id, ManagesClients $clients): JsonResponse
{
    Gate::authorize('manage-sso-clients');

    return response()->json($clients->find($id));
}

public function update(Request $request, string $id, ManagesClients $clients): JsonResponse
{
    Gate::authorize('manage-sso-clients');
    // Sólo exponemos nombre y callback. Las políticas quedan bajo control del operador.
    $input = $request->validate([
        'name' => ['sometimes', 'required', 'string', 'max:255'],
        'redirect_uri' => ['sometimes', 'required', 'string'],
    ]);

    return response()->json($clients->update($id, new ClientChanges(
        name: $input['name'] ?? null,
        redirectUri: $input['redirect_uri'] ?? null,
    )));
}

public function revoke(string $id, ManagesClients $clients): JsonResponse
{
    Gate::authorize('manage-sso-clients');

    return response()->json($clients->revoke($id));
}
```

En `routes/web.php`, con sesión y CSRF del anfitrión:

```php
Route::middleware('auth')->prefix('admin/sso-clients')->group(function () {
    Route::get('/', [SsoClientController::class, 'index']);
    Route::get('/{id}', [SsoClientController::class, 'show']);
    Route::patch('/{id}', [SsoClientController::class, 'update']);
    Route::post('/{id}/revoke', [SsoClientController::class, 'revoke']);
});
```

### Artisan usa los mismos servicios

```sh
php artisan sso:clients
php artisan sso:clients --status=all --per-page=20 --page=1 --json
php artisan sso:client-update UUID --name="Aplicación cliente" --redirect=https://app.test/sso/callback
php artisan sso:client-update UUID --first-party=1 --require-pkce=1 --restrict-users=1
php artisan sso:client-update UUID --backchannel= --post-logout=
php artisan sso:client-revoke UUID
```

Las banderas booleanas de edición exigen `0` o `1`; omitirlas conserva el valor.
`--backchannel=` y `--post-logout=` vacían esas URLs. No existe `--trust-backchannel`
en edición: la confianza saliente se configura por separado. Los comandos retornan 0
al completar y 1 ante validación/cliente inválido o edición de un revocado. La consola
es una interfaz del operador; no usa el Gate HTTP de tu CRUD ni expone secretos.

## Alta directa desde un servicio del anfitrión

```php
use LaravelSso\Server\ClientDefinition;
use LaravelSso\Server\Contracts\RegistersClients;

$created = app(RegistersClients::class)->create(new ClientDefinition(
    name: 'Aplicación cliente',
    redirectUri: 'https://app.test/sso/callback',
    firstParty: true,
));

$id = $created->id;
$secret = $created->plainSecret();
```

`firstParty: true` omite consentimiento para un sistema tuyo aprobado. La asignación
explícita de usuarios sigue siendo obligatoria. El servicio no crea ni cambia usuarios,
contraseñas, permisos o asignaciones; tampoco habilita SSO, altera sesiones, envía HTTP,
escribe `.env` ni genera respuestas de consola/web.

Puedes resolverlo aun con `SSO_SERVER_ENABLED=false`, siempre que sus tablas y las de
Passport estén preparadas. Activar los adaptadores de protocolo es una decisión separada.
La inyección de `Clients` concreta también funciona, pero el contrato permite sustituir
o decorar la implementación desde un service provider de tu aplicación.

## Controlador de tu CRUD

Este controlador y su ruta pertenecen a **tu proyecto**, no se publican automáticamente
por el paquete. Define la habilidad `manage-sso-clients` usando tus Gates/Policies o tu
sistema actual de permisos; el paquete no crea roles de administrador.

```php
namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use LaravelSso\Server\ClientDefinition;
use LaravelSso\Server\Contracts\RegistersClients;

final class SsoClientController
{
    public function store(Request $request, RegistersClients $clients): JsonResponse
    {
        Gate::authorize('manage-sso-clients');

        $input = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'redirect_uri' => ['required', 'string'],
        ]);

        $created = $clients->create(new ClientDefinition(
            name: $input['name'],
            redirectUri: $input['redirect_uri'],
            firstParty: true, // Sólo para sistemas propios aprobados por tu aplicación.
        ));

        return response()->json([
            'client_id' => $created->id,
            'client_secret' => $created->plainSecret(),
        ], 201)->header('Cache-Control', 'no-store');
    }
}
```

En `routes/web.php` del anfitrión, que ya aplica sesión y CSRF:

```php
use App\Http\Controllers\SsoClientController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->post('/admin/sso/clients', [SsoClientController::class, 'store']);
```

El Gate es parte de la autorización de tu CRUD. La validación de negocio del paquete
siempre vuelve a comprobar URLs/políticas, aunque tu formulario ya haya validado los
campos. No copies flags sensibles desde `$request->all()`: tu aplicación autorizada
decide si un cliente es público, first-party o admite todas las cuentas.

Blade, Vue, React o Inertia pueden usar tu controlador. Este servicio no depende de
ninguno. Mantén el secreto en el backend del cliente Laravel al configurar la conexión;
la respuesta del ejemplo sólo corresponde al administrador que acaba de darlo de alta.

## Opciones de ClientDefinition

| Propiedad | Valor predeterminado / significado |
| --- | --- |
| `name` | Obligatorio; nombre no vacío, máximo 255 caracteres, sin controles |
| `redirectUri` | Callback exacto obligatorio |
| `public` | `false`: cliente confidencial; el cliente Laravel de la suite utiliza secreto |
| `firstParty` | `false`: muestra consentimiento; `true` sólo para sistemas propios aprobados |
| `requirePkce` | `true`; nunca puede ser false con public=true |
| `restrictUsers` | `true`: exige asignación central; false permite todas las cuentas elegibles |
| `backchannelLogoutUri` | `null`; destino exacto que debe figurar en la allowlist del operador |
| `postLogoutRedirectUri` | `null`; URL exacta de retorno después del logout |

Las URLs requieren HTTPS, sin credenciales, fragmentos o controles y con máximo 2048
bytes. HTTP sólo se admite con `sso-server.allow_http=true` fuera de producción.
Una URL de callback no autoriza por sí sola peticiones salientes a ese servidor.

Ejemplo con logout, **después de autorizar el destino en la configuración del anfitrión**:

```php
$created = $clients->create(new ClientDefinition(
    name: 'Aplicación cliente',
    redirectUri: 'https://app.test/sso/callback',
    firstParty: true,
    backchannelLogoutUri: 'https://app.test/sso/backchannel-logout',
    postLogoutRedirectUri: 'https://app.test/',
));
```

El array `sso-server.backchannel_allowed_urls` debe incluir exactamente ese back-channel.
Puedes mantenerlo en configuración o cargarlo desde el almacenamiento administrado por
tu aplicación. Si lo guardas en DB, tu app debe persistirlo y cargarlo para web, consola
y workers. Un `config([...])` aislado en una petición no persiste esa confianza.
El servicio no cambia la allowlist ni edita `.env` por dar de alta un cliente.

## Resultado y secreto inicial

`CreatedClient` expone:

```php
$created->id;             // UUID del cliente creado.
$created->plainSecret();  // Secreto inicial o null para un cliente público.
```

El secreto está disponible en ese resultado de creación; no puede recuperarse desde
el hash almacenado en Passport. El método puede leerse mientras conserves el objeto
en memoria: no es un token que se consuma al llamar al getter.

JSON, `var_dump` y serialización PHP del resultado incluyen sólo el ID. Al deserializar,
el secreto es `null`. Para mostrarlo al administrador debes accederlo explícitamente,
como en el controlador anterior. No registres el secreto, el objeto mediante reflexión
ni una respuesta que lo incluya en logs; no lo guardes en localStorage.

## Validación, transacción y eventos

`create()` valida primero y lanza `Illuminate\Validation\ValidationException` con errores
por campo (`name`, `redirect_uri`, `require_pkce`, `backchannel_logout_uri`,
`post_logout_redirect_uri`). Laravel puede presentarlos como 422 en tu controlador.

El alta guarda el cliente Passport y su metadato SSO en una transacción de la conexión
predeterminada. Se exige que Passport use esa misma conexión y UUID. El evento de auditoría
`client.registered` se despacha después del commit, sin nombre, URLs ni secreto.
Una falla al guardar metadatos revierte también el cliente OAuth.

Puedes incluir el servicio dentro de una transacción propia para coordinarlo con datos
del anfitrión; el rollback externo revierte ambos registros y su auditoría. Si abres una
transacción exterior en SQL, debes reintentar esa transacción completa ante serialización
o deadlock; un savepoint no actualiza el snapshot.

El método `validate($definition)` permite comprobar los mismos requisitos sin escribir.
`validate($definition, trustBackchannel: true)` es un preflight para un operador que va
a configurar explícitamente ese destino: **no lo autoriza ni persiste**. `create()` nunca
acepta esa excepción y siempre vuelve a exigir la allowlist vigente.

## Artisan utiliza el mismo servicio

El comando conserva sus opciones y salida:

```sh
php artisan sso:client "Aplicación cliente" https://app.test/sso/callback --first-party --backchannel=https://app.test/sso/backchannel-logout --trust-backchannel --post-logout=https://app.test/
```

Traduce los argumentos a `ClientDefinition`, llama al contrato y presenta ID/secreto.
`--allow-confidential-without-pkce` equivale a `requirePkce=false` y
`--allow-all-users` a `restrictUsers=false`. Los defaults son S256 y usuarios asignados.

`--trust-backchannel` continúa siendo una acción explícita **del adaptador de consola**:
valida primero, persiste la URL exacta en el entorno SSO y limpia config cache. Tu CRUD
no recibe esa escritura automáticamente. La configuración de confianza y la transacción
SQL son operaciones distintas; si el alta SQL falla después, revisa la configuración
que autorizaste desde consola.

## Tu aplicación conserva la administración

Puedes componer `RegistersClients`, `Accounts`, `ClientUsers`, `PasswordRecovery` y las
abstracciones del cliente con tu Auth y UI. Los adaptadores incluidos son una comodidad;
no convierten al paquete en tu aplicación ni aportan un panel administrativo genérico.
Los contratos cubren creación, consulta, edición y revocación de clientes; no publican
rutas CRUD ni un panel, no borran registros ni cambian los permisos de tu proyecto.

[Ejemplo descendente con cuentas y asignaciones](CENTRAL-ACCOUNTS.md) ·
[Extensión](EXTENDING.md) · [Instalación](QUICKSTART.md).

## Evidencia

[Gestión de clientes: resultados locales y hashes](../conformance/client-management-service-results.json).
**320 pruebas / 1677 aserciones** pasan en PHP 8.3/8.4/8.5, con **21 / 142** casos nuevos.
La selección SQL pasa **29 / 180** por perfil MySQL/PostgreSQL y ambos aislamientos:
21 casos de servicio, cuatro carreras nuevas y cuatro regresiones concurrentes.
El grupo OIDC propio pasa **106 / 414** y el E2E conserva **53 controles HTTP** aprobados.
Larastan, Pint global, Composer y tres doctores pasan; fuentes SQL coincidentes,
laboratorio detenido, originales y migraciones intactos. Las pruebas verifican el
control de acceso del CRUD anfitrión, tokens/códigos invalidados, aislamiento entre
aplicaciones, secretos conservados/ausentes de lecturas y auditoría, consentimiento
pendiente, edición parcial, límites, CLI y rollback. Sin publicación de versiones.

### Extracción previa de creación

[Resultados locales con hashes](../conformance/client-registration-service-results.json).
Calidad local: **299 pruebas / 1535 aserciones** por versión PHP 8.3.36/8.4.27/8.5.12,
grupo OIDC propio **106 / 414**, Larastan y Pint global aprobados. Los **18 casos nuevos /
101 aserciones** pasan también en MySQL/PostgreSQL bajo READ COMMITTED y REPEATABLE READ;
son los casos de esta extracción, no la matriz SQL histórica completa. El E2E mantiene
sus **53 controles HTTP** aprobados usando Artisan con el servicio nuevo. Los tres
doctores y los controles Composer también pasan. Las fuentes SQL coinciden entre
workspace y contenedor; el laboratorio queda detenido.

Las pruebas cubren DI sin habilitar protocolo, un controlador propio con Gate, paridad
Artisan/servicio, HTTPS/PKCE/allowlist, ausencia de cambios en `.env`/usuarios, rollback
de ambas tablas y auditoría, y exposición explícita del secreto. No hay cambios de
migración en esta extracción, versiones publicadas ni cambios en contraseñas existentes.
Las pruebas corrigen dos errores iniciales del mock de Artisan: captura por valor de
una variable modificada por otro callback y lectura repetida de un buffer consumible.
No requirieron cambios de lógica del servicio. Se conservan los logs de ambos intentos.
No se repiten OIDF oficial, navegadores cross-site o carga de 30 minutos ni se presenta
esta ejecución como CI remota o revisión externa.
