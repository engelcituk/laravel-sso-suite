# Instalar el servidor

Antes de publicación, agrega un repositorio path a tu `composer.json` (fusiona, no reemplaces
tu configuración). Ajusta la ruta a este checkout:

```json
{
  "repositories": [{"type": "path", "url": "C:/ruta/laravel-sso/packages/*",
    "options": {"versions": {"laravel-sso/server": "0.1.x-dev", "laravel-sso/contracts": "0.1.x-dev"}}}]
}
```

```sh
composer require laravel-sso/server:0.1.x-dev
php artisan vendor:publish --tag=passport-migrations
php artisan vendor:publish --tag=sso-server-config
php artisan vendor:publish --tag=sso-server-migrations
php artisan migrate
php artisan passport:keys
php artisan sso:keys
```

Publica las migraciones **una sola vez**: el publisher de Laravel cambia timestamps y
repetirlo puede generar copias. Los ejemplos evitan esto en su script de setup.
Si Passport ya está instalado conserva sus migraciones y claves existentes.
Si ya instalaste esta primera versión, copia únicamente la nueva migración
`2026_11_13_000004_allow_optional_oidc_nonce.php` de packages/server/database/migrations
a las migraciones de tu aplicación y ejecuta migrate. No republiques todas las anteriores.
En el laboratorio, tools/setup-examples.php realiza esa actualización sin duplicarlas.
`sso:keys` nunca reemplaza archivos existentes; genera RSA 3072, sin exponer la clave privada.

Activa `SSO_SERVER_ENABLED=true` y configura `SSO_ISSUER=https://identity.example.org` sin
slash final. El issuer debe coincidir exactamente con el origen/ruta pública real del OP.
Las rutas actuales se montan desde la raíz; utiliza un origen dedicado, sin subdirectorio.
Guarda claves y APP_KEY en almacenamiento protegido, con ACL de Windows o permisos Unix.

Configura el guard en `sso-server.guard` y su proveedor Eloquent. El modelo central necesita
`name`, `email`, `email_verified_at` y el indicador configurable `active_column` (por defecto
`is_active`). Las credenciales y recuperación son responsabilidad de tu aplicación central,
usando Laravel Hash y SessionGuard. Define la ruta nombrada `login`. El ejemplo implementa
un formulario Blade, CSRF y rate limiting. El paquete no reemplaza tu login existente.
Las solicitudes externas GET/POST de /oidc/authorize y /oidc/logout no exigen CSRF de
Laravel. Las decisiones locales usan /oidc/consent y /oidc/logout/confirm con CSRF.
Conserva los nombres de rutas en las vistas personalizadas y utiliza SessionGuard::login
para emitir Login, requerido por la prueba de reautenticación prompt/max_age.

OIDC y Passport deben utilizar la misma conexión de base de datos predeterminada, necesaria
para consumir códigos y persistir tokens/contexto en una transacción. Se rechaza una
conexión Passport diferente. Las claves JWT OIDC son independientes de las claves OAuth.
El servidor OIDC sólo habilita Authorization Code y no cambia los grants OAuth del anfitrión.

Agrega a `sso-server.backchannel_allowed_urls` los endpoints exactos de los clientes y registra:

```sh
php artisan sso:client "Application One" https://one.example.org/sso/callback --first-party --backchannel=https://one.example.org/sso/backchannel-logout --post-logout=https://one.example.org/
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

Las instalaciones existentes deben incorporar las migraciones nuevas
`000005_add_client_pkce_policy`, `000006_bind_access_tokens_to_auth_codes` y
`000007_bind_authorized_claims`, conservando
el historial de migraciones. Los clientes existentes reciben `require_pkce=true`.
El OP enlaza los tokens nuevos con su código para revocarlos al detectar reutilización;
los tokens anteriores a esa migración conservan su vencimiento original.

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

Ejecuta `sso:deliver-logouts` con scheduler o worker externo cada minuto. Por ejemplo en
`routes/console.php` del OP:

```php
Schedule::command('sso:deliver-logouts')->everyMinute()->withoutOverlapping();
```

Activa `php artisan schedule:work` en desarrollo o el cron de Laravel en despliegue.
Sin procesamiento, la bandeja permanece pendiente; la comprobación remota del cliente sigue
siendo necesaria para acotar la revocación. Ver [sesiones](SESSIONS.md).
