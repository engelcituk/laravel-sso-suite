# Administrar aplicaciones, cuentas y acceso

El flujo de administración consiste en registrar una aplicación cliente, crear una
cuenta central, asignarle acceso a esa aplicación y configurar el cliente.
Cada persona tiene una cuenta central y puede estar asignada a uno o varios sistemas.
Cada aplicación conserva sus roles y permisos locales.

No hay sincronización de contraseñas ni copia periódica de usuarios. En un cliente nuevo,
el primer login válido de una persona asignada crea su cuenta local automáticamente.
La administración se puede componer desde servicios públicos o usar mediante Artisan.
Tu proyecto implementa su propio panel y permisos, sin stack obligatorio. La creación
de clientes comparte `RegistersClients` entre CRUD y consola:
[contrato y controlador de ejemplo](CLIENT-REGISTRATION-SERVICE.md).
`ManagesClients` agrega listado paginado, consulta, edición parcial y revocación desde
ese mismo CRUD. Desde consola usa `sso:clients`, `sso:client-update UUID --name="Nuevo nombre"`
y `sso:client-revoke UUID`. Guardar cambios efectivos invalida sus códigos/tokens;
revocar es definitivo y conserva cuentas, contraseñas y acceso a los otros sistemas.
Los formularios de registro/recuperación incluidos son adaptadores opcionales.

## 1. Prepara el servidor central

En un Laravel nuevo con DB, APP_KEY y migraciones base normales ya preparadas, sigue
[la instalación del servidor](INSTALL-SERVER.md):

```sh
composer require laravel-sso/server:0.1.x-dev laravel-sso/contracts:0.1.x-dev
php artisan sso:install-server --configure --migrate --keys
php artisan sso:doctor-server
```

Responde el issuer público, por ejemplo `https://identidad.test`. Antes de publicación
necesitas el repositorio Composer path explicado en [la guía rápida](QUICKSTART.md).
No necesitas un starter kit. El modelo central convencional tiene `name`, `email` único
y contraseña; no se agregan columnas a users. La tabla `password_reset_tokens` es la
normal de Laravel, creada por sus migraciones base, no por una migración SSO adicional.

## 2. Registra una aplicación — en el servidor

```sh
php artisan sso:client "Aplicación cliente" https://app.test/sso/callback --first-party --backchannel=https://app.test/sso/backchannel-logout --trust-backchannel --post-logout=https://app.test/
```

Guarda el `SSO_CLIENT_ID` y el secreto que muestra una vez. Usaremos `ID_DEL_CLIENTE`
como marcador de ese UUID; sustitúyelo en los comandos siguientes.

**Las aplicaciones nuevas creadas por este comando sólo admiten usuarios asignados.**
`--first-party` omite consentimiento; no omite esa asignación. La opción avanzada
`--allow-all-users` habilita explícitamente cualquier cuenta central elegible y no permite
administrar denegaciones individuales con los comandos de asignación.

## 3. Crea y asigna una cuenta — en el servidor

Si quieres introducir manualmente la contraseña inicial:

```sh
php artisan sso:user persona@example.test --name="Usuario de ejemplo" --client=ID_DEL_CLIENTE
```

Pide y confirma la contraseña en un **prompt oculto**. No existe opción `--password`
para dejarla en el historial de comandos. El mínimo predeterminado es 12 caracteres y
el máximo 72 bytes; se guarda sólo su hash. La salida muestra `SSO_SUBJECT`, el ID central.

Si prefieres que la persona establezca su propia contraseña desde un enlace de correo:

```sh
php artisan sso:user persona@example.test --name="Usuario de ejemplo" --client=ID_DEL_CLIENTE --send-reset
```

Necesita correo configurado. Crea la cuenta con un valor aleatorio descartado y envía
un enlace de establecimiento de contraseña, con caducidad y un solo uso. No imprime el
token ni una contraseña provisional. Si falla el envío, informa que la cuenta ya existe:
revisa el correo y usa recuperación; no repitas el alta para intentar actualizarla.

**No ejecutes ambas variantes para la misma cuenta:** el comando crea cuentas nuevas;
no adopta ni actualiza cuentas que ya existan con ese email. Para asignar una cuenta
central existente, usa su ID, por ejemplo 7:

```sh
php artisan sso:client-user ID_DEL_CLIENTE 7
```

La asignación es idempotente. Puedes repetir `--client=...` en el alta para varios
sistemas, o ejecutar el comando de asignación por cada sistema.

Para retirar un acceso:

```sh
php artisan sso:client-user ID_DEL_CLIENTE 7 --remove
```

Retira esa asignación y revoca sus tokens de ese sistema. No borra usuarios ni modifica
roles, contraseñas o acceso a otras aplicaciones. El cliente estricto rechaza la siguiente
petición protegida; con política acotada se aplica su ventana de comprobación. Esta
operación no envía logout back-channel: la comprobación remota y los tokens revocados
hacen efectiva la denegación. Una petición ya autorizada puede terminar.

## 4. Configura la aplicación cliente

En el proyecto cliente, instala el paquete y responde issuer, callback, ID y secreto:

```sh
composer require laravel-sso/client:0.1.x-dev laravel-sso/contracts:0.1.x-dev
php artisan sso:install-client --configure --migrate
php artisan sso:doctor
```

Abre `https://app.test/sso/login`. Una cuenta asignada se autentica en Identidad y
regresa a la aplicación cliente. En greenfield se crea su registro local y vínculo al primer acceso;
no necesita fábrica propia ni comando de vinculación. Otro usuario central sin esa
asignación no obtiene acceso, aunque tenga contraseña válida.

### Aplicaciones con usuarios locales existentes

Una cuenta local existente conserva su ID, contraseña y relaciones. Crea o selecciona
su cuenta central y asígnala a la aplicación. **Verifica que ambas cuentas representan
a la misma persona** y ejecuta en el proyecto cliente:

```sh
php artisan sso:link-user 1 7
```

Sustituye 1 por el ID local existente y 7 por el `SSO_SUBJECT` central. Esto guarda sólo
el vínculo; no sincroniza ni cambia su cuenta. Es necesario porque el servidor central
no puede conocer la propiedad del usuario local únicamente por su correo. No se hace
email linking automático. En clientes nuevos este paso no existe.

## Registro público opcional

El alta administrativa funciona con el registro público cerrado, valor predeterminado:

```dotenv
SSO_SERVER_REGISTRATION=closed
```

Para permitir que las personas creen su propia cuenta central:

```dotenv
SSO_SERVER_REGISTRATION=open
```

Reconstruye config cache/reinicia procesos persistentes. Abre `/sso/auth/register` o
consume su JSON desde tu UI. El registro no inicia sesión automáticamente, no marca
el email como verificado y **no asigna sistemas**. El operador debe autorizar las
aplicaciones después. Los campos adicionales de rol, ID o cliente no se aceptan.

## Recuperación de contraseña central

Está habilitada para la autenticación incluida (`SSO_SERVER_PASSWORD_RESET=true`).
Desde `/sso/auth/login`, el enlace **Olvidé mi contraseña** lleva a
`/sso/auth/forgot-password`. La solicitud devuelve siempre el mismo mensaje para cuentas
conocidas/desconocidas y para el throttle del broker. Los errores de entrega se registran
sin email, token o credenciales; la respuesta pública no confirma la existencia de cuenta.

Configura el transporte normal de Laravel, por ejemplo con los valores de tu proveedor:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=TU_HOST_SMTP
MAIL_PORT=587
MAIL_USERNAME=TU_USUARIO_SMTP
MAIL_PASSWORD=TU_SECRETO_SMTP
MAIL_FROM_ADDRESS=acceso@tu-dominio.example
MAIL_FROM_NAME="Identidad"
```

Conserva TLS y los ajustes requeridos por tu proveedor. `MAIL_MAILER=log` sirve sólo
para desarrollo: el enlace queda en logs privados y no llega al destinatario. El doctor
comprueba broker, proveedor, conexión y tabla; no envía correo ni acredita entrega SMTP.

El broker seleccionado mediante `sso-server.password_broker` debe apuntar al mismo
proveedor Eloquent del guard central, usar almacenamiento de tokens en DB y la misma
conexión. Usa el broker `users` predeterminado del Laravel limpio. Expiración y espera
entre solicitudes provienen de `auth.passwords.<broker>.expire/throttle` (normalmente
60 minutos y 60 segundos). Los tokens se guardan como hash.

Un token válido cambia únicamente la contraseña y recaller de su cuenta objetivo, y
revoca sus dispositivos, sesiones centrales y tokens SSO. No inicia sesión; vuelve al
login y usa la contraseña nueva. No cambia contraseñas de clientes ni de otras personas.
La contraseña sólo cambia al completar ese enlace válido: no hay actualizaciones masivas.

## Contrato JSON y frontend propio

Todos los POST requieren sesión/cookie central y CSRF. Obtén el token desde
`GET /sso/auth/session`, usa `Accept: application/json` y `X-CSRF-TOKEN`.

| Endpoint central | Datos / respuesta |
| --- | --- |
| GET `/sso/auth/register` | `interaction=register`, `csrf_token`; sólo con registro abierto |
| POST `/sso/auth/register` | name, email, password, password_confirmation; 201 con `registered`, `login_url` |
| GET `/sso/auth/forgot-password` | `interaction=forgot_password`, `csrf_token` |
| POST `/sso/auth/forgot-password` | email; 202 con `accepted`, `message` genérico |
| GET `/sso/auth/reset-password/{token}?email=...` | `interaction=reset_password`, `csrf_token`, token y email del formulario |
| POST `/sso/auth/reset-password` | email, token, password, password_confirmation; 200 con `password_reset`, `login_url` |

`/sso/auth/session` añade `registration_available` y `password_reset_available`. Un POST
inválido devuelve 422; CSRF inválido 419; límite de peticiones 429. Cuando no envías
Accept JSON, se usan formularios mínimos y redirecciones. `SSO_SERVER_LOGIN_UI=false`
devuelve JSON también en los GET; tu UI puede ser Vue, React, Blade, Inertia o cualquier
otra que use ese contrato. No se instala un starter kit ni una dependencia JavaScript.
Si desactivas las vistas, proporciona también la pantalla que recibe el enlace del
correo: puedes declarar el GET `/sso/auth/reset-password/{token}` en `routes/web.php`
del anfitrión con tu frontend, conservando el POST del paquete. El enlace siempre usa
el issuer configurado; no depende del header Host del visitante.

En modo `auto`, si ya existe login del anfitrión, los POST alternativos de registro y
recuperación del paquete quedan cerrados junto con su backend de contraseña. Conserva
su MFA y sus políticas. Elegir `authentication=package` es una decisión explícita para
usar estos endpoints; no es un backend de MFA.

## Migraciones y bases ya instaladas

Se mantiene **una migración servidor**, una cliente y ninguna en contracts. En la
migración inicial del servidor se consolidan `sso_clients.restrict_users` y la octava
tabla propia `sso_client_users`; no se agrega una migración incremental ni se modifica
el esquema de users. Las siete tablas anteriores conservan sus definiciones existentes.

Una base que ya registró la migración inicial no recibe esos cambios al copiar archivos
ni al repetir `migrate`. Necesita un procedimiento revisado y respaldado que adapte
sólo el esquema SSO. El doctor detecta el faltante. El instalador no convierte automáticamente
esquemas antiguos; `migrate:fresh` no es un procedimiento de actualización.

Las filas antiguas conservan la política abierta por compatibilidad (`restrict_users=false`);
las aplicaciones creadas ahora con `sso:client` quedan restringidas por defecto. Para
adoptar la asignación en una aplicación antigua, prepara primero sus membresías y activa
`restrictUsers: true` mediante `ManagesClients::update()` o `sso:client-update UUID --restrict-users=1`.
Ese cambio invalida sus códigos y tokens, como cualquier edición efectiva. La configuración
copiada se conserva: incorpora las opciones nuevas que decidas usar.

## Evidencia

[Resultados y hashes de esta fase](../conformance/central-accounts-results.json).
Pasaron **281 pruebas / 1434 aserciones** por versión en PHP 8.3.36, 8.4.27 y 8.5.12,
**106 / 414** del grupo OIDC propio, Larastan nivel 5 sin baseline, Pint global con
caché nueva y siete manifiestos/plataforma/audit de Composer. El E2E pasó **53 controles**;
los tres doctores aprobaron. Las dos carreras nuevas pasaron en MySQL/PostgreSQL bajo
READ COMMITTED y REPEATABLE READ: **2 pruebas / 9 aserciones por perfil**. Las fuentes
de paquetes/pruebas en el contenedor coinciden con el workspace y el laboratorio se detuvo.

La primera carrera de retirada falló en PostgreSQL REPEATABLE READ: el lector esperaba
la inscripción bloqueada pero conservaba un snapshot previo al token. Se corrigió
con una escritura de la política ya existente durante la emisión, forzando el reintento
de la transacción que esperaba. Se repitieron los cuatro perfiles y la matriz PHP/HTTP
completa. Se conservan ese fallo y el primer intento HTTP cuyo fixture carecía del
metadato Composer de namespace que necesita Laravel al renderizar correo.
Las pruebas cubren formularios/JSON, CSRF, registro cerrado/abierto, no asignación desde
el registro público, cuentas duplicadas intactas, invitación manual, recuperación
válida/incorrecta/caducada/reemplazada/repetida, revocación y aislamiento entre sistemas.
El E2E usa correo a un log privado, sin destinatarios externos, y contraseñas sólo de
cuentas sintéticas nuevas. Conserva los intentos fallidos del fixture. Las campañas
OIDF oficiales, navegadores y carga anteriores no se repiten para este ajuste.

Para repetir el recorrido aislado:

```sh
composer test
composer test:conformance
composer lint
composer analyse
php tools/agnostic-e2e.php
```

Para las dos carreras nuevas usa `tools/database-lab.ps1` con el filtro
`ConcurrencyTest::test_central_`, ambos motores y ambos aislamientos. Los fixtures
viven en `.runtime`/el volumen de laboratorio y no deben apuntarse a datos de aplicación.
