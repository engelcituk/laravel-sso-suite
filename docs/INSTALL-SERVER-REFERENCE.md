# Referencia avanzada del servidor

Para instalarlo paso a paso, usa [Instalar el servidor](INSTALL-SERVER.md) o la
[guía rápida completa](QUICKSTART.md). Esta página amplía opciones y comportamiento.
El instalador con `--migrate` ejecuta sólo las migraciones propias/Passport descritas
abajo. No ejecuta pendientes ajenos. [Integración agnóstica y contratos JSON](STACK-AGNOSTIC.md).
La creación también se expone mediante `RegistersClients`/`Clients`, sin consola ni
escritura de entorno. [Contrato y ejemplos para CRUD](CLIENT-REGISTRATION-SERVICE.md).
`ManagesClients` añade consulta paginada, edición parcial y revocación por aplicación;
Artisan expone `sso:clients`, `sso:client-update` y `sso:client-revoke` con la misma lógica.

## Bases ya instaladas

Si ya registraste la migración inicial SSO, copiar su definición nueva no actualiza
la base. Revisa únicamente el esquema propio con respaldo y un procedimiento específico;
no borres historial ni uses `migrate:fresh`. Conserva las tablas, contraseñas y claves
existentes. El instalador mantiene el timestamp de la única migración publicada.

## Instalación y configuración ampliadas

Antes de publicación, agrega un repositorio path a tu `composer.json` (fusiona, no reemplaces
tu configuración). Ajusta la ruta a este checkout:

```json
{
  "repositories": [{"type": "path", "url": "C:/ruta/laravel-sso/packages/*",
    "options": {"versions": {"laravel-sso/server": "0.1.x-dev", "laravel-sso/contracts": "0.1.x-dev"}}}]
}
```

```sh
composer require laravel-sso/server:0.1.x-dev laravel-sso/contracts:0.1.x-dev
php artisan sso:install-server --configure --migrate --keys
```

`sso:install-server` se puede repetir: conserva configuración, timestamp y una única
migración publicada. El publisher genérico de Laravel cambia timestamps al repetirlo;
prefiere el instalador. Opciones y diagnóstico en [auditoría y operaciones](AUDIT-OPERATIONS.md).
Si Passport ya está instalado conserva sus migraciones y claves existentes.
El paquete contiene una única migración, `create_sso_tables.php`, que crea las ocho tablas
con su esquema final: política PKCE, nonce opcional, contexto de claims, enlace código/token
y entregas de logout, dispositivos, credenciales Recordarme y asignaciones `sso_client_users`.
`sso_clients.restrict_users` selecciona acceso por asignación. No hay migraciones incrementales en esta versión en desarrollo.
Los ejemplos sincronizan su copia de esa definición sin cambiar el timestamp ni duplicarla.
Si conservas copias de las migraciones incrementales de un checkout anterior, retíralas
del directorio de migraciones y actualiza la copia de `create_sso_tables.php`.
Esto no modifica una base ya migrada ni su historial; la definición consolidada se aplica
a instalaciones nuevas. No ejecutes `migrate:fresh` sobre datos que debas conservar.
`sso:keys` nunca reemplaza archivos existentes; genera RSA 3072, sin exponer la clave privada.
Tras configurar y activar el OP ejecuta `php artisan sso:doctor-server --json`.

Activa `SSO_SERVER_ENABLED=true` y configura `SSO_ISSUER=https://identity.example.org` sin
slash final. El issuer debe coincidir exactamente con el origen/ruta pública real del OP.
Las rutas actuales se montan desde la raíz; utiliza un origen dedicado, sin subdirectorio.
Guarda claves y APP_KEY en almacenamiento protegido, con ACL de Windows o permisos Unix.

Configura el guard en `sso-server.guard` y su proveedor Eloquent. El modelo central necesita
su columna de contraseña. `active_column` es opcional, con valor predeterminado `null`;
si seleccionas una columna booleana existente, se exige que sea verdadera. No se agrega
ninguna columna a users. Los atributos de perfil, como `name`, `email`
y `email_verified_at`, se omiten cuando no existen. El paquete incluye altas manuales,
registro abierto opt-in y recuperación mediante el broker Laravel para el modelo convencional.
[Flujo de sistemas, cuentas y JSON](CENTRAL-ACCOUNTS.md). El modo `auto` utiliza la ruta `login` del anfitrión si existe;
si no hay login ni login_url, habilita el formulario/backend opcional `/sso/auth/login`.
El backend valida sin rehash ni escritura de atributos de users. Si existe login/MFA del
anfitrión, su POST de contraseña queda cerrado. `authentication=package` es una elección
explícita para usar ese backend desde una UI propia; consulta [los contratos](STACK-AGNOSTIC.md).
El ejemplo incluye Recordarme opt-in y un enlace a `/sso/devices`. El provider registra
el middleware central y escucha los eventos nativos de autenticación/logout/reset de
contraseña. La cookie por dispositivo sustituye el recaller persistente compartido;
configuración, integración y política de replay en [Recordarme](REMEMBER-DEVICES.md).
Las solicitudes externas GET/POST de /oidc/authorize y /oidc/logout no exigen CSRF de
Laravel. Las decisiones locales usan /oidc/consent y /oidc/logout/confirm con CSRF.
Conserva los nombres de rutas en las vistas personalizadas y utiliza SessionGuard::login
para emitir Login, requerido por la prueba de reautenticación prompt/max_age.

OIDC y Passport deben utilizar la misma conexión de base de datos predeterminada, necesaria
para consumir códigos y persistir tokens/contexto en una transacción. Se rechaza una
conexión Passport diferente. Las claves JWT OIDC son independientes de las claves OAuth.
El servidor OIDC sólo habilita Authorization Code y no cambia los grants OAuth del anfitrión.

Registra los endpoints exactos de los clientes. `--trust-backchannel` autoriza explícitamente
el destino en `SSO_BACKCHANNEL_ALLOWED_URLS`; puedes mantener una allowlist manual:

```sh
php artisan sso:client "Application One" https://one.example.org/sso/callback --first-party --backchannel=https://one.example.org/sso/backchannel-logout --trust-backchannel --post-logout=https://one.example.org/
```

`--first-party` omite consentimiento sólo para aplicaciones que controles y hayas aprobado.
Sin esa opción se presenta consentimiento CSRF con transacción de un uso. `--public` crea
un cliente sin secreto; S256 sigue siendo obligatorio. El comando muestra el secreto de
cliente confidencial una sola vez. No lo copies a logs, tickets ni control de versiones.
No hay registro dinámico ni URLs suministradas por visitantes.

S256 se exige por defecto también a los clientes confidenciales. Sólo para interoperar
con clientes confidenciales antiguos puede registrarse uno con
`--allow-confidential-without-pkce`. Esta opción guarda `require_pkce=false` en esa
inscripción; no modifica el resto de clientes ni el cliente Laravel, que siempre envía
S256. Se rechaza combinarla con `--public`. Si una solicitud incluye parámetros PKCE,
deben ser S256 válidos y el token endpoint siempre comprueba el verifier correspondiente.
La evaluación Basic oficial se ejecutó con esta excepción explícitamente autorizada.

La tabla `sso_clients` nace con `require_pkce=true`. El OP enlaza cada access token con su
código de autorización para revocarlo al detectar reutilización.

`sso-server.claim_attributes` asigna los atributos normales de profile/email a columnas del
modelo; admite rutas de atributos JSON casteados, por ejemplo `given_name => oidc_profile.given_name`.
No exige agregar todas esas columnas a users: los datos inexistentes se omiten. La lectura
no carga relaciones Eloquent. Configura sólo fuentes reales y fiables; los atributos de
administración, contraseñas y roles no forman parte del catálogo de claims solicitado por RPs.

El parámetro OIDC claims permite pedir atributos concretos en userinfo o id_token. El OP
guarda esa selección junto al código y al access token, la muestra en el consentimiento y
respeta value/values; no puede ampliarse desde el POST de consentimiento ni desde token.
Un atributo normal esencial ausente se omite sin inventarlo. Las restricciones sub deben
coincidir con el usuario autenticado. Para acr se anuncia y devuelve sólo `0`, sin afirmar
MFA ni un nivel de aseguramiento superior. Una solicitud esencial de otro acr se rechaza.

El paquete registra automáticamente `sso:deliver-logouts` cada minuto y limpieza cada hora
cuando está activo. Si prefieres programarlos tú mismo, usa `'schedule' => false` antes
de declarar tareas equivalentes. Ejemplo de programación manual en `routes/console.php`:

```php
Schedule::command('sso:deliver-logouts')->everyMinute()->withoutOverlapping();
```

Activa `php artisan schedule:work` en desarrollo o el cron de Laravel en despliegue.
Sin procesamiento, la bandeja permanece pendiente; la comprobación remota del cliente sigue
siendo necesaria para acotar la revocación. Ver [sesiones](SESSIONS.md).

Para la limpieza programa también `sso:prune-server --limit=1000`, por ejemplo cada hora
con `withoutOverlapping()`. Conserva hints recientes, access tokens todavía vigentes y
sesiones con entregas pendientes; sólo borra estado propio del paquete. El scheduler
ya descubre las tareas del paquete; necesita el proceso/cron normal de Laravel. Plazos,
límites y evidencia en [concurrencia y retención](DATABASE-CONCURRENCY.md).
