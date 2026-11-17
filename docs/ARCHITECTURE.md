# Arquitectura

Estado experimental. No certificado por OpenID Foundation ni listo para producción.

Tres paquetes Composer independientes: `laravel-sso/server`, `laravel-sso/client` y
`laravel-sso/contracts`. Namespace provisional `LaravelSso`; antes de publicar se
pueden renombrar los manifiestos y namespaces conjuntamente (no en tiempo de ejecución).
El cliente no depende de Passport ni del servidor. Redis es opcional.
La rama `composer` del mismo repositorio aloja un índice Composer y tres ZIP independientes.
Las descargas apuntan a commits inmutables y conservan la estructura de cada paquete;
la automatización actualiza el índice tras aprobar la calidad de `main`.
[Distribución y lockfiles](GITHUB-DISTRIBUTION.md).

Cada paquete extiende un proyecto anfitrión, no reemplaza su aplicación. Los servicios
públicos se componen desde sus propios controladores, jobs o consola. `RegistersClients`
expone la creación transaccional compartida por `Clients` y el adaptador `sso:client`;
la UI, administración y autorización pertenecen al anfitrión. La operación no activa
el protocolo ni modifica su configuración. [Contrato público](CLIENT-REGISTRATION-SERVICE.md).
`ManagesClients` comparte consulta, edición y revocación con los tres adaptadores Artisan.
Los cambios y la emisión de códigos/tokens se serializan mediante la fila propia del
cliente. Cada edición efectiva invalida sus códigos/tokens; la revocación es definitiva.
No se revoca la sesión central ni se alteran las cuentas o las otras aplicaciones.

## Identidad y autorización

El OP conserva credenciales y autentica mediante el guard Laravel de la aplicación anfitriona.
El RP conserva el usuario Eloquent del anfitrión, con sus credenciales locales y ACL.
La tabla propia `sso_identities` vincula `(issuer, subject)` con la clase concreta del
modelo y su clave local, mediante un índice único sensible a mayúsculas. No se agregan
columnas ni se modifican filas existentes en `users`. Greenfield inserta sólo cuentas
nuevas con una lista acotada de campos. Nunca se vincula por email. La integración
conserva las credenciales locales para permitir el modo Auth nativo del anfitrión.

Con `SSO_CLIENT_ENABLED=false`, los middleware integrados utilizan Auth local sin
consultas SSO ni HTTP al OP. Con `true`, exigen identidad OIDC y sesión central vigente.
Los cambios de modo exigen autenticación nueva. Se integran automáticamente rutas
convencionales `web` de Auth del guard seleccionado. Rutas personalizadas requieren
patrones o `sso.local` / `auth` + `sso.session` explícitos. Las rutas
del cliente siempre se registran y se habilitan en ejecución mediante `sso.enabled`.
Las altas greenfield usan una fábrica incluida; `automatic` permite una fábrica propia
para otros esquemas y `restricted` sólo admite vínculos explícitos. Las cuentas existentes
se conservan. [Contrato actual](GREENFIELD-PROVISIONING.md), [fase histórica](OPTIONAL-AUTH.md).

El servidor ofrece login mínimo/JSON opcionales para Laravel sin starter kit y conserva
el login del anfitrión cuando existe. No obliga a agregar columnas a users ni dependencia
de frontend. [Flujos nuevos/existentes y límites](STACK-AGNOSTIC.md).

La administración central incluye creación de sistemas, altas de cuentas y asignaciones
en `sso_client_users`, sin roles remotos ni cambios de esquema en users. Los sistemas
nuevos registrados por CLI exigen asignación; la autorización, consentimiento, emisión
de tokens y UserInfo/estado comprueban esa política. El registro público está cerrado
por defecto y no asigna aplicaciones. La recuperación usa el broker de contraseña
Laravel del mismo modelo/conexión y revoca las sesiones del objetivo.
[Contrato de administración](CENTRAL-ACCOUNTS.md).

## Primera vertical

1. RP crea state, nonce y verifier aleatorios, con caducidad y consumo único.
2. OP valida cliente, redirect exacto, scope openid y PKCE S256 antes de autenticar.
3. Passport/League emite y consume el código; una extensión acotada de su repositorio
   asocia nonce, auth_time, sid y selección autorizada de claims al identificador del código.
4. La respuesta Bearer de League añade un ID Token RS256 sólo al intercambio OIDC.
5. RP obtiene discovery/JWKS exclusivamente de URLs previamente confiadas, valida firma,
   kid, algoritmo, issuer, audience, fechas y nonce antes de aprovisionar.
6. SessionGuard inicia sesión local y regenera el identificador.

Los tokens OAuth no son contraseñas ni reemplazan la sesión Laravel. El OP y cada RP
tienen base de datos, cookies y APP_KEY independientes.

## Límites de extensión

Contracts comparte identidad verificada, DTO de auditoría y utilidades PHP para archivos
de instalación e informes, sin Laravel/Passport. El contrato de aprovisionamiento pertenece
al cliente. Los modelos, guard y políticas son configurables; no hay ACL ni frontend externo
obligatorio. El OP incluye vistas Blade de consentimiento/logout y dispositivos propios.
Las rutas tienen prefijos propios. Los instaladores conservan archivos y una sola migración
por paquete. Los doctores son de lectura y añaden HTTP sólo en el RP online.
Los eventos de seguridad se emiten con referencias HMAC después de commit; sink privado
opt-in y límites en [auditoría y operaciones](AUDIT-OPERATIONS.md).

La integración con los repositorios Bridge y ResponseTypeInterface de Passport/League
no está documentada como API estable por Laravel: es un riesgo de compatibilidad explícito.
Las pruebas integradas y el lockfile deben comprobarla tras cada actualización. Se compone
un AuthorizationServer aislado con los repositorios de Passport, sin sustituir los grants,
rutas ni respuesta OAuth de la aplicación anfitriona.

## Sesiones

Una sesión global opaca se guarda por separado de la sesión web central. Cada código y
token OIDC queda ligado a ella. El cliente verifica su vigencia antes de operaciones
autenticadas; la política estricta falla cerrada. Ver `SECURITY-MODEL.md` y el roadmap.

En el OP, `sso_devices` agrupa los sid de un navegador y `sso_remember_tokens` conserva
hashes e historial de rotación. Un dispositivo puede recuperar una sesión web hasta el
vencimiento absoluto elegido, conservando auth_time. Sus controles propios exigen
contraseña reciente y CSRF. Revocación y emisión se coordinan con el bloqueo del
dispositivo antes del sid; la limpieza respeta hints y entregas pendientes.
