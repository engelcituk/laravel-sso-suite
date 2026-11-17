# Auditoría, instalación y diagnóstico

La suite expone `LaravelSso\Contracts\AuditEvent` y comandos Artisan en ambos paquetes.
El catálogo contiene 40 nombres. Los eventos se despachan después del commit y no
requieren una tabla adicional de auditoría. El servidor conserva una migración inicial,
el cliente otra y contracts ninguna. Contracts no depende de Laravel ni de Passport.
La tabla de identidad del cliente es propia del paquete; no cambia el esquema de users.

## Eventos de seguridad

Ambos paquetes despachan el mismo DTO inmutable mediante el dispatcher de Laravel. Tiene
`version=1`, `id` aleatorio de 128 bits, `component` (`server`/`client`), `name`,
`occurred_at` (Unix UTC), `references` y `context`. El catálogo cerrado está en
`AuditEvent::NAMES`; un nombre o campo desconocido se rechaza.

| Componente | Eventos |
| --- | --- |
| Servidor, autenticación | `auth.failed`, `auth.login`, `auth.password_reset`, `auth.registered` |
| Servidor, dispositivos | `device.registered`, `device.revoked`, `device.renamed` |
| Servidor, confirmación | `password.required`, `password.rejected`, `password.confirmed` |
| Servidor, Recordarme | `remember.restored`, `remember.replayed`, `remember.expired` |
| Servidor, sesiones | `session.created`, `session.revoked` |
| Servidor, autorización | `code.issued`, `authorization.rejected`, `consent.approved`, `consent.denied` |
| Servidor, tokens | `token.issued`, `token.rejected`, `token.replayed` |
| Servidor, entrega logout | `logout.retry`, `logout.delivered` |
| Servidor, operación | `client.registered`, `client.updated`, `client.revoked`, `client.user_granted`, `client.user_revoked`, `keys.created` |
| Cliente, identidad | `identity.linked`, `identity.provisioned`, `identity.rejected` |
| Cliente, login/sesión | `login.completed`, `login.rejected`, `session.denied` |
| Cliente, logout | `logout.local`, `logout.received`, `logout.duplicate`, `logout.rejected` |

`auth.login` indica autenticación nativa aceptada, no garantiza entrada de contraseña:
la aplicación puede invocar `SessionGuard::login`. La restauración de Recordarme tiene
su evento propio y conserva `auth_time`. `remember.replayed` exige verificar el secreto;
un secreto adivinado no genera una acusación de replay ni revoca otro dispositivo.
`remember.expired` incluye credenciales persistentes ya no válidas por caducidad,
revocación, suspensión o cambio de contraseña. `identity.provisioned` indica resolución
aprobada, sin actualizar atributos del usuario existente; `created` distingue altas.
`identity.linked` registra una vinculación explícita nueva; repetirla no duplica el evento.

Sólo se admiten referencias `issuer`, `subject`, `client`, `device`, `sid`, `delivery`.
Se transforman mediante HMAC-SHA256 con la clave del encrypter y separación por versión,
componente y tipo. No se emiten identificadores originales; cada instalación y rol tiene
seudónimos distintos. Rotar APP_KEY cambia esas referencias. No sirven para correlacionar
usuarios entre RP. El contexto sólo admite `remembered`, `created`, `attempt`, `count`
con valores booleanos/enteros.

No se incluyen contraseñas/hashes de contraseña, secretos, cookies, códigos, verifier,
state, nonce, JWT, access tokens, jti, emails, nombres, etiquetas, IP, UA, modelos,
requests, respuestas HTTP ni texto de excepciones. `authorization.rejected` cubre errores
del canal de respuesta ya validado; los rechazos tempranos de sintaxis/URL, CSRF y
throttling pertenecen también a la observabilidad HTTP del anfitrión. No es un registro
exhaustivo de cada solicitud que llega a Laravel.

Los eventos pendientes se emiten después del commit de la transacción exterior usando
la conexión propietaria; un rollback descarta los callbacks, también los de rechazo si
el llamador abrió una transacción. El aprovisionamiento respeta la conexión del modelo.
Revocar repetidamente el mismo dispositivo/SID no duplica sus eventos de transición.
Las entregas sólo registran el resultado si conservan su lease; los duplicados de un
Logout Token se reconocen separadamente sin reaplicar revocación. No se promete orden
global entre callbacks anidados ni entrega exactamente una vez.

### Conectar un destino

Puedes escuchar el DTO en el provider de tu aplicación:

```php
use Illuminate\Support\Facades\Event;
use LaravelSso\Contracts\AuditEvent;

Event::listen(AuditEvent::class, function (AuditEvent $event): void {
    // Enviar únicamente este sobre al destino privado elegido por la aplicación.
    logger()->channel('security')->info('sso.audit', $event->jsonSerialize());
});
```

O habilita el sink incluido, sin escribir un listener:

```dotenv
# OP
SSO_SERVER_AUDIT_LOG=true
SSO_SERVER_AUDIT_CHANNEL=security
# RP (en su propio entorno)
SSO_CLIENT_AUDIT_LOG=true
SSO_CLIENT_AUDIT_CHANNEL=security
```

Define `security` en `config/logging.php` y reconstruye el config cache después de cambiar
configuración. Si omites el canal, usa el predeterminado de Laravel. El sink está desactivado
por defecto; los eventos se despachan igualmente. Elige sink o listener para evitar dos
copias en el mismo destino. El evento anterior `IdentityProvisioned` se conserva para
extensiones del aprovisionamiento; contiene el modelo y no debe volcarse a logs.
El sink escribe con nivel `info`: configura un canal que lo acepte. El canal de los
ejemplos usa `warning` inicialmente; habilitar sólo la variable de auditoría no cambia
ese filtro. El laboratorio de despliegue usó explícitamente nivel `info`.

La entrega de auditoría es síncrona después del commit. Una excepción de un listener o
del canal puede propagarse, pero no deshace datos ya confirmados. Para persistencia,
retención, control de acceso y reintentos usa un destino privado administrado por el
anfitrión/listener en cola. No hay una outbox durable de auditoría: una caída entre commit
y callback puede perder un evento. La outbox de logout sigue siendo independiente y durable.
Este límite es distinto de la corrección transaccional que verifican las pruebas.

## Instalar sin duplicar archivos

La creación de sistemas no depende del instalador ni de consola: el contrato público
`RegistersClients` comparte validación/persistencia con `sso:client`, emite
`client.registered` después de commit y no modifica `.env`. La opción explícita
`--trust-backchannel` sigue siendo responsabilidad del adaptador CLI.
[Servicio, resultado y ejemplo para tu CRUD](CLIENT-REGISTRATION-SERVICE.md).

```sh
# En la aplicación OP, después de composer require
php artisan sso:install-server
# En cada RP
php artisan sso:install-client
```

Por defecto sólo copian configuración y la única migración inicial cuando faltan. Buscan
por el sufijo de la migración, conservan un timestamp existente y no sobrescriben ningún
archivo. Detectan dos copias de esa migración antes de copiar configuración y terminan
con código 1. Una ejecución concurrente tampoco sobrescribe un archivo ya creado.

Sin opciones no editan `.env` ni habilitan SSO. `--configure` escribe exclusivamente
variables `SSO_*` en el `.env` existente, activa el paquete y limpia config/route cache.
Nunca crean usuarios/ACL/clientes, generan APP_KEY ni cambian Auth/rutas del anfitrión.
No son comandos de actualización de un esquema antiguo.

```sh
php artisan sso:install-server --configure --migrate --keys
php artisan sso:install-client --configure --migrate
```

`--keys` crea claves OAuth/OIDC faltantes; conserva pares existentes y no sobrescribe
los incompletos. Comprueba correspondencia y completitud con el doctor. `--migrate`
ejecuta sólo la única migración SSO y, en el servidor, las de Passport si aún no existe
`oauth_clients`. No ejecuta migraciones pendientes ajenas ni repara un Passport parcial.
`--force` se reenvía exclusivamente a esa ejecución explícita en producción. Nunca llama
`fresh`, `reset` ni `rollback`. Repetir la instalación no agrega una segunda migración
por paquete. Para configuraciones ya copiadas, incorpora las nuevas opciones
manualmente; el instalador preserva tus cambios. Las tareas de logout/limpieza ahora
se descubren automáticamente; si mantienes tareas manuales usa `schedule=false`.
Las preguntas del cliente ocultan el secreto; no hay opción CLI para exponerlo en el
historial. [Flujo completo](STACK-AGNOSTIC.md).

## Diagnósticos reproducibles

El servidor comprueba también el esquema de asignaciones y, al usar la autenticación
incluida, el modelo convencional y broker de recuperación. No envía correo ni acredita
entrega SMTP. [Comandos de sistemas/usuarios y recuperación](CENTRAL-ACCOUNTS.md).

En modo `greenfield`, el doctor añade `greenfield_model`: comprueba el esquema
convencional y el índice único de email sin crear cuentas ni modificar tablas.
El [alta de primer login](GREENFIELD-PROVISIONING.md) es independiente del diagnóstico.

```sh
php artisan sso:doctor-server
php artisan sso:doctor-server --json
php artisan sso:doctor --offline
php artisan sso:doctor --json
```

El doctor OP siempre es local. Comprueba activación, origen issuer, guard/modelo/columnas,
sesiones/cookies, tablas Passport/SSO y campos nuevos, una única copia publicada,
claves RSA OIDC y OAuth coincidentes, conexión Passport compartida, TTLs positivos,
allowlist de logout y entregas con más de cinco minutos de retraso. No entrega notificaciones.

Con el cliente desactivado, el doctor RP aprueba el modo local sin exigir tablas ni
configuración SSO y sin HTTP. Activado, comprueba SessionGuard/modelo local en la misma
conexión, índice único `(issuer,subject)` de `sso_identities`, collation sensible en MySQL, tablas/columnas,
sesiones/cookies, callback/política/scopes/TTLs y confianza explícita de discovery/status.
Por defecto añade los GET de discovery y JWKS: verifica issuer exacto, allowlists,
RS256/S256 y un conjunto RSA utilizable. `--offline` no envía HTTP. Ninguna modalidad
intercambia códigos ni prueba credenciales.

`--json` devuelve `version`, `passed` y `checks[{code,status,message}]`; mensajes fijos
con acciones concretas, sin valores configurados ni excepciones. Código de salida 0
si todos pasan, 1 si falla alguno; recoge múltiples fallos en una ejecución. Instalación
y diagnóstico pueden arrancar aunque la configuración de ejecución del paquete sea
inválida; los guards de seguridad de la aplicación web siguen activos.

Un resultado aprobado es una instantánea. No prueba scheduler/queue, permisos/ACL,
TLS/proxy públicos, todas las collations ni login completo, y no es conformidad/certificación
OIDC. [Eventos tras commit en Laravel](https://laravel.com/framework/docs/events#dispatching-events-after-database-transactions).

La vinculación aprobada de un usuario existente se realiza con
`sso:link-user {user} {subject} --issuer=https://identity.example.org`. No actualiza perfil,
hash ni permisos y rechaza una identidad con otro propietario. No asocia por correo.

## Validación histórica de auditoría/operaciones

Las pruebas nuevas ejercitan transacciones y rollback reales, sesiones/credenciales nativas,
replay, logs opt-in, exclusión de secretos, diagnóstico negativo y publicación local repetida
con conservación de archivos. También migran una base de prueba y generan/verifican claves.
Resultados ejecutados el 2026-11-14 con Laravel 13.42.0, Passport 13.10.0 y PHPUnit 12.5.44:

| Evaluación | Resultado real |
| --- | --- |
| PHP Windows 8.3.36 / 8.4.27 / 8.5.12 | 209 pruebas / 982 aserciones en cada versión, sin fallos |
| Linux PHP 8.3.36, MySQL 8.4.13 / REPEATABLE READ | 224 pruebas / 1055 aserciones, sin fallos ni saltos |
| Linux PHP 8.3.36, PostgreSQL 18.7 / READ COMMITTED | 224 pruebas / 1053 aserciones, sin fallos ni saltos |
| Grupo normativo propio | 106 pruebas / 414 aserciones, aprobado |
| Larastan/PHPStan nivel 5 | sin errores ni baseline |
| Laravel Pint | 136 archivos, aprobado |
| E2E tres hosts y dos cookie jars | SSO, ACL, Recordarme, dispositivos, CSRF, logout y tres entregas, aprobado |
| Doctores reales en E2E | OP: 10 comprobaciones; cada RP: 7, incluidas discovery/JWKS por HTTP; todas aprobadas |
| Cinco planes oficiales OIDF | 84 FINISHED: 58 PASSED, 0 FAILED/WARNING, 16 REVIEW verificados y 10 SKIPPED justificados |

Los 25 casos nuevos y las aserciones añadidas al grant real pasaron también en ambos
motores SQL. No cambió ninguna migración ni dependencia. Los resúmenes versionables
sin secretos son [SQL](../conformance/database-results.json),
[OIDF](../conformance/oidf-results.json) y [revisión OIDF](../conformance/oidf-review.json).
Los resultados locales no constituyen una certificación externa.

Artefactos privados, ignorados por Git:

- `.runtime/audit-php83.log`, `audit-php84.log`, `audit-php85.log` y sus JUnit.
- `.runtime/audit-analyse.log`, `audit-pint.log`, `audit-own-conformance.log`, `audit-e2e.log`.
- `.runtime/operations-e2e-diagnostics.json`: informes de los tres doctores.
- `.runtime/database-lab/results/audit-final-20261114-215054-*`: ambos motores y huella de código.
- `.runtime/conformance/official-audit-operations-20261114-215322/`: exports originales,
  diez ZIP y `runtime-check.json` con huellas idénticas del OP y workspace.
- `.runtime/audit-oidf-review.log`: revisión repetida de 16 REVIEW y 10 SKIPPED.

Se conservaron las evidencias anteriores. Los contenedores y servidores temporales se
detuvieron al terminar. No se publicó código, paquetes, imágenes ni resultados, ni se
ejecutó CI remota. Este informe conserva el cierre de auditoría/operaciones. La fase
posterior completó [dos despliegues, navegadores cross-site y carga local de 30 min](DEPLOYMENT-VALIDATION.md).
CI remota y evaluación externa siguen fuera del cierre; ver [roadmap](ROADMAP.md).
