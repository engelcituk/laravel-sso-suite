# Auditoría, instalación y diagnóstico

La fase agrega `LaravelSso\Contracts\AuditEvent` y comandos Artisan en ambos paquetes.
No agrega tablas ni migraciones: servidor conserva su única definición inicial y cliente
la suya. El paquete contracts continúa sin dependencias de Laravel/Passport; contiene DTOs
y utilidades PHP de instalación/informes. No se ha publicado ningún paquete ni evidencia.

## Eventos de seguridad

Ambos paquetes despachan el mismo DTO inmutable mediante el dispatcher de Laravel. Tiene
`version=1`, `id` aleatorio de 128 bits, `component` (`server`/`client`), `name`,
`occurred_at` (Unix UTC), `references` y `context`. El catálogo cerrado está en
`AuditEvent::NAMES`; un nombre o campo desconocido se rechaza.

| Componente | Eventos |
| --- | --- |
| Servidor, autenticación | `auth.failed`, `auth.login`, `auth.password_reset` |
| Servidor, dispositivos | `device.registered`, `device.revoked`, `device.renamed` |
| Servidor, confirmación | `password.required`, `password.rejected`, `password.confirmed` |
| Servidor, Recordarme | `remember.restored`, `remember.replayed`, `remember.expired` |
| Servidor, sesiones | `session.created`, `session.revoked` |
| Servidor, autorización | `code.issued`, `authorization.rejected`, `consent.approved`, `consent.denied` |
| Servidor, tokens | `token.issued`, `token.rejected`, `token.replayed` |
| Servidor, entrega logout | `logout.retry`, `logout.delivered` |
| Servidor, operación | `client.registered`, `keys.created` |
| Cliente, identidad | `identity.provisioned`, `identity.rejected` |
| Cliente, login/sesión | `login.completed`, `login.rejected`, `session.denied` |
| Cliente, logout | `logout.local`, `logout.received`, `logout.duplicate`, `logout.rejected` |

`auth.login` indica autenticación nativa aceptada, no garantiza entrada de contraseña:
la aplicación puede invocar `SessionGuard::login`. La restauración de Recordarme tiene
su evento propio y conserva `auth_time`. `remember.replayed` exige verificar el secreto;
un secreto adivinado no genera una acusación de replay ni revoca otro dispositivo.
`remember.expired` incluye credenciales persistentes ya no válidas por caducidad,
revocación, suspensión o cambio de contraseña. `identity.provisioned` incluye una
actualización aprobada de usuario existente; `created` permite distinguir creación.

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

La entrega de auditoría es síncrona después del commit. Una excepción de un listener o
del canal puede propagarse, pero no deshace datos ya confirmados. Para persistencia,
retención, control de acceso y reintentos usa un destino privado administrado por el
anfitrión/listener en cola. No hay una outbox durable de auditoría: una caída entre commit
y callback puede perder un evento. La outbox de logout sigue siendo independiente y durable.
Este límite es distinto de la corrección transaccional que verifican las pruebas.

## Instalar sin duplicar archivos

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

No editan `.env`, no habilitan SSO, no crean usuarios/ACL/clientes, no generan APP_KEY,
no instalan Passport ni alteran tablas automáticamente. No son comandos de actualización
de un esquema antiguo. Conserva las migraciones/claves Passport del anfitrión o prepáralas
según [la guía OP](INSTALL-SERVER.md). Configura el modelo local según [la guía RP](INSTALL-CLIENT.md).

```sh
php artisan sso:install-server --keys
php artisan sso:install-server --migrate
php artisan sso:install-client --migrate
```

`--keys` crea el par OIDC sólo si falta; conserva un par existente, y un par incompleto
falla sin sobrescribirlo. Comprueba su correspondencia con el doctor. `--migrate` ejecuta
el comando Laravel `migrate` y todas las migraciones pendientes del anfitrión, no sólo SSO.
`--force` se reenvía exclusivamente a esa ejecución explícita en producción. Nunca llama
`fresh`, `reset` ni `rollback`. Repetir la instalación no agrega una segunda migración
por paquete. Para configuraciones ya copiadas, incorpora las nuevas opciones de auditoría
manualmente; el instalador preserva tus cambios.

## Diagnósticos reproducibles

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

El doctor RP comprueba activación, SessionGuard/modelo de identidad sin credenciales,
índice único `(sso_issuer,sso_subject)` y collation sensible en MySQL, tablas/columnas,
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

## Validación de esta fase

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
ejecutó CI remota. Siguen fuera de este cierre otros despliegues, navegadores cross-site,
carga prolongada y evaluación externa; ver [roadmap](ROADMAP.md).
