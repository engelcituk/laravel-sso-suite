# Usar SSO con tu Laravel y tu frontend

El servidor y el cliente son extensiones de Laravel Auth. No requieren Breeze, Jetstream,
Fortify, un starter kit, Vue, React, Inertia, Livewire ni un paquete de permisos.
**Elige tu interfaz; el paquete proporciona autenticación central, protocolo, sesiones
y vínculos.** Para ejecutar los pasos en orden usa la [guía rápida](QUICKSTART.md).

La prioridad es greenfield: un servidor central y proyectos cliente concebidos así
desde el inicio. El soporte para proyectos existentes es adicional. La configuración
usa `provision=greenfield` en instalaciones nuevas: crea y vincula una cuenta local al
primer login validado. Las cuentas existentes se conservan; los esquemas personalizados
pueden seleccionar una fábrica del proyecto.

## Qué ocurre al entrar

1. La aplicación cliente envía al navegador a `/sso/login`.
2. El cliente lo redirige a Identidad mediante Authorization Code + PKCE.
3. Identidad usa el login del proyecto o su formulario opcional. Sólo Identidad recibe la contraseña central.
4. El callback del cliente valida el resultado y resuelve `(issuer, subject)`; si es nuevo,
   crea y vincula la cuenta local automáticamente en modo greenfield.
5. Laravel Auth entra como la cuenta local vinculada. Los permisos siguen siendo los del cliente.

Un sujeto central puede tener ID local 42 en Aplicación A e ID local 18 en Aplicación B.
Las cuentas no necesitan compartir ID, correo o contraseña. La misma persona puede tener
permisos distintos en cada aplicación.
El cliente no recibe ni guarda la contraseña central.

## Qué necesitas preparar y qué integra el paquete

| Responsabilidad del proyecto | Responsabilidad del paquete |
| --- | --- |
| Laravel instalado, APP_KEY, DB y tablas base | Configuración SSO y una migración propia por paquete |
| Operar las cuentas centrales, correo y políticas de acceso | Comandos de alta/asignación, registro público opcional, recuperación central y alta local greenfield |
| Frontend, navegación y recursos protegidos | Rutas SSO, callback, validadores y control de sesiones |
| TLS, cookies, procesos y scheduler de Laravel | Registro de tareas de logout/limpieza |
| Roles, permisos, Gates y Policies | Autenticar al modelo local sin cambiar su ACL |
| Login/MFA propio, si ya existe | Conservarlo y evitar un segundo backend que lo eluda |

En un Laravel limpio puedes instalar el servidor sin implementar primero una pantalla
de login: hay una opcional. En un cliente limpio puedes utilizar sólo `/sso/login` y
rutas `auth`. **Todavía necesitas cuentas centrales**; no se siembran usuarios demo ni se inventan
contraseñas centrales. El cliente greenfield sí crea su cuenta local al primer login,
sin fábrica propia; no necesitas crearla previamente. [Contrato de alta](GREENFIELD-PROVISIONING.md).

## Servidor limpio y servidor existente

Después de `sso:install-server --configure --migrate --keys`:

| Configuración de autenticación | Comportamiento |
| --- | --- |
| `auto`, sin login del anfitrión ni login_url | Disponible backend de contraseña y formulario opcional `/sso/auth/login` |
| `auto`, con ruta `login` o login_url | Delega al login del anfitrión; POST de contraseña del paquete devuelve 404 |
| `host` | Usa el login proporcionado por el anfitrión; debes configurar una ruta/URL válida |
| `package` | Habilita explícitamente el backend del paquete, aunque exista login propio |

`SSO_SERVER_AUTHENTICATION` selecciona el modo. `login_route` selecciona un nombre
personalizado y `SSO_SERVER_LOGIN_URL` una URL de login. No configures esa URL como el
propio `/sso/auth/login` en modo `host/auto`: debe llevar a una pantalla del anfitrión.

El guard se descubre desde `auth.defaults.guard`; puedes fijarlo con
`SSO_SERVER_GUARD`. Requiere SessionGuard y proveedor Eloquent. Si el login propio usa
otro guard, selecciona ese mismo guard para SSO. Tu controlador debe autenticar mediante
Laravel Auth y emitir sus eventos normales; `prompt=login`/`max_age` necesitan evidencia
de validación nueva de credenciales (`Validated` y `Login`).

No necesitas `is_active`. Por defecto una cuenta existente es elegible; su política de
acceso también depende de tu autenticación. Para suspensiones posteriores al login,
configura `SSO_SERVER_ACTIVE_COLUMN` con una columna booleana existente que se comprobará
al validar sesiones. Una columna configurada inexistente o falsa rechaza la cuenta.
No cambies esta política en una instalación existente sin revisar su intención.

El backend de contraseña opcional valida las credenciales sin rehash ni escritura de
atributos de `users`, regenera la sesión, usa CSRF y limita intentos. Incluye registro
central cerrado/abierto y recuperación por correo; no incorpora MFA. Si ya usas una
autenticación del anfitrión, conserva sus flujos/políticas. [Contrato de cuentas](CENTRAL-ACCOUNTS.md).
El `remember` del paquete utiliza sus propias credenciales por dispositivo.

## Frontend propio en el servidor

Puedes conservar tu UI de autenticación sin consumir el backend del paquete. Por ejemplo,
un starter kit con MFA continúa usando su controlador; `auto` impide el acceso alternativo
por contraseña. No selecciones `package` sólo para cambiar el aspecto de ese login.

Para construir tu propia UI sobre el backend opcional, elige explícitamente:

```dotenv
SSO_SERVER_AUTHENTICATION=package
SSO_SERVER_LOGIN_UI=false
SSO_SERVER_LOGIN_URL=https://identidad.test/acceso
```

Implementa `/acceso` en tu aplicación con Vue, React, Blade, Livewire o Inertia.
El formulario es tuyo; la API permanece en el mismo origen y usa sesión/cookies.
No se requiere un paquete JavaScript. Ejemplo de JS ejecutado en Identidad:

```js
const sessionResponse = await fetch('/sso/auth/session', {
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
});
const session = await sessionResponse.json();

// email y password proceden de los campos de tu formulario; no los almacenes.
const response = await fetch('/sso/auth/login', {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': session.csrf_token,
    },
    body: JSON.stringify({ email, password, remember: false }),
});
const result = await response.json();
if (response.ok) {
    window.location.assign(result.redirect_to);
} else {
    // 422: muestra result.errors; 419: renueva sesión/CSRF; 429: espera antes de reintentar.
    showErrors(result.errors ?? { login: [result.message ?? 'No se pudo iniciar sesión.'] });
}
```

No uses este ejemplo para evitar un MFA del anfitrión. Las credenciales son centrales,
se envían sólo a Identidad y el secreto de cliente no aparece nunca en JavaScript.
Si usas otro campo identificador existente, configura `login_field` y envíalo en lugar de email.

| Endpoint central | Contrato |
| --- | --- |
| GET `/sso/auth/session` | JSON: `authenticated`, `csrf_token`, `password_login_available` |
| GET `/sso/auth/login` | Vista opcional o estado JSON con Accept JSON / login_ui=false |
| POST `/sso/auth/login` | CSRF; credenciales; JSON `authenticated`, `redirect_to` o redirección normal |
| POST `/sso/auth/logout` | CSRF; cierra sesión central; JSON `signed_out` o redirección normal |

`password_login_available=false` significa que debes usar la autenticación del anfitrión.
El POST del paquete devuelve 404 en ese caso. Para sustituir sólo el formulario Blade
opcional puedes configurar `'login_view' => 'auth.central'`; la vista debe enviar CSRF
al POST del paquete. La vista incluida no requiere compilación ni assets externos.
Una vista personalizada que necesite scripts/estilos propios debe servirse desde una
ruta del anfitrión: la respuesta de la vista opcional usa una CSP restrictiva.

## Consentimiento y logout con interfaz propia

El protocolo sigue siendo OIDC con redirecciones del navegador. Las pantallas incluidas
son opcionales: el mismo flujo puede solicitar datos con `Accept: application/json`.

| Interacción | Datos JSON |
| --- | --- |
| Autorización que necesita consentimiento | `interaction=consent`, `csrf_token`, `transaction`, `account`, `clientName`, `scopes`, `requestedClaims`, `selectAccount` |
| GET/POST `/oidc/logout` que necesita confirmación | `interaction=logout`, `csrf_token`, `transaction`, `account`, `clientName` |
| POST `/oidc/logout/confirm` con Accept JSON | `signed_out` y `redirect_to` cuando corresponda |

Conserva los parámetros originales de la autorización y la cookie de sesión. El
consentimiento se envía mediante formulario POST a `/oidc/consent` con `_token`,
`transaction` y `approve=1` o `0`; la decisión de logout va a `/oidc/logout/confirm`.
Las transacciones son de un uso. La aprobación OIDC termina mediante redirección o
`form_post`: usa navegación/formulario normal al continuar, no un fetch que intente
recorrer redirecciones a otro origen. Los errores y respuestas sin interacción conservan
la semántica del protocolo. No hay un redirect público para URLs arbitrarias.

Los clientes propios registrados `--first-party` normalmente no muestran consentimiento.
Las pantallas de dispositivos son herramientas opcionales del paquete; no fijan el stack
de tu aplicación. Puedes seguir usando las vistas incluidas sin instalar un starter kit.

## Cliente nuevo: lo mínimo

1. Instala/configura con `sso:install-client --configure --migrate`.
2. Conserva el modo predeterminado `greenfield`; la cuenta central debe tener email válido.
3. Crea una ruta `web` protegida con `auth` y abre `/sso/login`: se crea y vincula la cuenta local.

No necesitas login local para usar SSO. No necesitas cambiar User, agregar traits,
modificar `bootstrap/app.php` ni implementar el callback. El paquete descubre el modelo
del proveedor de Auth; puedes fijar `SSO_CLIENT_GUARD` y `SSO_USER_MODEL` si tu configuración
lo requiere. El modelo y las tablas SSO deben usar la misma conexión.

## Cliente existente: qué cambia

El middleware del paquete se incorpora al grupo `web`, después de la sesión y antes
de la autenticación. Con SSO activo:

- Una ruta con `auth` del guard predeterminado seleccionado o `auth:guard_seleccionado`
  exige sesión SSO y comprueba revocación remota antes de entrar al controlador.
- Rutas convencionales de login/registro/reset/logout local se interceptan por los
  patrones de `local_auth_routes`/`local_auth_paths` de la configuración.
- Un GET de navegador a esas rutas pasa al login central. POST/JSON devuelve 403.
- Una sesión local anterior no se convierte en SSO. La autorización del recurso sigue
  en tus Gates/Policies y middleware de permisos.

Con SSO apagado, Auth local continúa y no necesita tablas SSO ni peticiones al OP.
Una sesión de origen SSO se invalida al entrar al flujo local. Cambiar modo requiere
reconstruir configuración/reiniciar procesos; no hay failover automático si falla Identidad.

Si usas Spatie Permission, conserva el trait y sus tablas/relaciones en tu modelo.
El paquete no asigna roles ni sincroniza permisos centrales. Tu `can(...)` continúa
operando sobre el usuario local. Lo mismo aplica a tus observers y lógica de negocio:
los eventos normales de login pueden activar listeners del anfitrión, que siguen
siendo responsabilidad de ese proyecto.

## Rutas personalizadas y otros guards

Revisa `config/sso-client.php`. Los patrones predeterminados cubren los nombres/paths
convencionales de Laravel y Fortify; no cubren automáticamente cualquier sistema propio.
Para rutas traducidas o prefijadas agrega sus patrones conservando los anteriores:

```php
'local_auth_routes' => ['login', 'login.*', 'logout', 'register', 'register.*',
    'password.*', 'two-factor.login', 'two-factor.login.*', 'verification.*', 'mi-acceso', 'mi-acceso.*'],
'local_auth_paths' => ['login', 'logout', 'register', 'forgot-password', 'reset-password',
    'reset-password/*', 'two-factor-challenge', 'email/verify', 'email/verify/*',
    'email/verification-notification', 'user/confirm-password', 'cuenta/acceso'],
```

O declara `sso.local` explícitamente antes de `guest` en esas rutas. Para middleware
Auth personalizados o guards múltiples, integra los recursos de forma explícita:

```php
Route::middleware(['web', 'sso.local'])->group(function () {
    // Tus rutas nativas de autenticación; evita duplicarlas.
});

Route::middleware(['web', 'auth:mi_guard', 'sso.session:strict'])->group(function () {
    // Recursos cuyo guard coincide con sso-client.guard.
});
```

En `routes/web.php` el grupo `web` ya está aplicado. No agregues una segunda definición
de rutas de Fortify o del starter kit: aplica/configura los middleware a las existentes.
Otros guards, `auth:guard1,guard2`, middleware de autorización propios y rutas API
stateless no se adivinan. Comprueba sus rutas con `route:list` y cubre su integración.

Para control manual completo usa `'integrate_auth' => false` y aplica `sso.local` /
`auth` + `sso.session` explícitamente. `sso.session:strict` revisa siempre el OP;
`sso.session` usa una ventana acotada por `session_check_interval` (30 segundos por defecto).
Una ruta con `sso.session` explícito conserva esa política; no se sustituye por strict.

## Vue, React, Blade e Inertia en el cliente

No se importa código de esos frameworks. Puedes usar el mismo backend desde cualquiera.
Para iniciar SSO, usa un enlace normal o:

```js
window.location.assign('/sso/login');
```

Para logout, usa un formulario POST de navegador con el token CSRF de **la aplicación cliente**:

```html
<form method="post" action="/sso/logout">
    <input type="hidden" name="_token" value="TOKEN_CSRF_DEL_CLIENTE">
    <button type="submit">Cerrar sesión global</button>
</form>
```

Obtén ese token mediante el mecanismo habitual de tu aplicación; no copies el de
Identidad. El valor del ejemplo es un marcador, no un token real. En Blade usa `@csrf`.
En modo local utiliza tu logout nativo; puedes mostrar el botón según el flag que
exponga tu backend.

Una petición JSON a un recurso protegido sin sesión SSO válida devuelve 401. Tu SPA
puede responder navegando completamente a `/sso/login`. No envíes credenciales a los
clientes ni guardes tokens/secreto de cliente en localStorage. En visitas Inertia que
interceptan login o acceso protegido, el paquete devuelve `409` con
`X-Inertia-Location`; el navegador realiza la navegación completa sin depender de la
librería Inertia en PHP. Para un botón explícito usa `<a>`, no una visita XHR a OIDC.

**Alcance:** esto es un cliente Laravel confidencial con sesión del servidor. Una SPA
servida por Laravel usa su backend como intermediario. Una SPA estática en otro dominio,
una app móvil o una API Bearer/Sanctum stateless requiere otro diseño de cliente y su
política de cookies/CORS; no se configura automáticamente por instalar este paquete.
Ser agnóstico del frontend no convierte todos esos flujos en el mismo protocolo.
El cliente Laravel usa además `/oidc/session`, extensión de esta suite; no se promete
compatibilidad directa con cualquier OP ajeno sin revisar ese contrato.

## Instalación repetible y actualización

`--configure` es la autorización explícita para escribir variables SSO y activar el
modo; sin ella los instaladores copian archivos faltantes. Conservan configuraciones
y migraciones existentes. `--migrate` sólo ejecuta la migración del paquete y, en el
servidor, las de Passport si aún no existe su tabla de clientes. No migra pendientes
ajenos, no cambia usuarios ni llama a `fresh/reset/rollback`. `--keys` conserva claves.

En configuraciones copiadas anteriormente, incorpora las nuevas opciones que quieras
usar. Ejemplos: `active_column` antes podía exigir `is_active`; `allowed_urls` o
`backchannel_allowed_urls` podían ser arrays literales; `schedule` automático puede
duplicar tareas manuales. El instalador no elimina esas decisiones. La plantilla nueva
lee las variables de entorno y deriva endpoints del issuer confiable; no confía en
URLs enviadas por visitantes.

Una migración ya registrada no se reaplica al copiar su definición actualizada.
Los cambios de esquema se consolidan en la migración inicial durante el desarrollo;
las bases ya instaladas necesitan un procedimiento revisado para sus tablas SSO.
Conserva respaldos y el historial; no uses `migrate:fresh` en una instalación con datos.

Los instaladores con `--configure` limpian caché de configuración y rutas. Al terminar
el despliegue reconstruye ambas y recarga procesos persistentes. Al cambiar sólo el
flag cliente basta reconstruir configuración y recargar procesos; su route cache sirve
en ambos modos. OPcache, Octane, FPM o workers persistentes necesitan la recarga propia
del entorno. Mantén scheduler de Laravel, claves privadas, DB y sesiones protegidos.

## Qué está comprobado

La [evidencia de integración agnóstica](AGNOSTIC-VALIDATION.md) distingue pruebas unitarias,
HTTP real y límites. Incluye servidor sin login ni columna extra, cliente nuevo y
cliente con Auth previo, ambos modos con config/route cache, CSRF, Code/PKCE, logout,
scheduler y conservación de usuarios/ACL. Los contratos JSON e Inertia están cubiertos
sin instalar un frontend. Eso no equivale a ejecutar una compilación real de cada
starter kit ni a una auditoría externa del despliegue.
