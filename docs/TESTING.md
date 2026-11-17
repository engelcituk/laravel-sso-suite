# Pruebas y evidencia

Estado del modo opcional: [run 43991616405](https://github.com/engelcituk/laravel-sso-suite/actions/runs/43991616405),
`b385141`, rama `codex/optional-client-auth`, **14/14 trabajos aprobados**. Por cada uno
de los nueve perfiles PHP 8.3/8.4/8.5 × locked/lowest/latest: **230 pruebas / 1076 aserciones**,
**106 / 414** normativas y E2E real aprobados. MySQL: **246 / 1152** por aislamiento;
PostgreSQL: **246 / 1150**, READ COMMITTED y REPEATABLE READ. Pint (147 archivos) y Larastan
pasan en los tres perfiles locked, manifiestos/plataforma/audit aprobados y sin advertencias
del runner. Los resultados de fases anteriores se conservan abajo como históricos.
[Informe completo y correcciones](OPTIONAL-AUTH.md), [JSON sin secretos](../conformance/optional-auth-results.json).

`tests/OptionalClientTest.php` añade 18 casos: Auth local sin tablas SSO ni HTTP, endpoints
desactivados, bloqueo del login nativo con SSO, activación de rutas registradas apagadas,
sesiones no transferibles entre modos, conservación de ID/perfil/hash/recaller/ACL,
vinculación explícita e idempotente sin email linking ni reasignación, fábrica obligatoria,
rollback de política, doctor local, callback/logout y esquema de users intacto tras up/down.
También prueba SsoOnly opt-in y ausencia de cambios requeridos al morph map del anfitrión.
La prueba de rutas verifica gating en ejecución; no ejecuta un despliegue con cachés reales
en ambos modos. El E2E remoto usa instalaciones nuevas de los ejemplos y HTTP loopback.

`composer test` ejecuta PHPUnit/Orchestra Testbench con Laravel real, SQLite y claves RSA
efímeras generadas en tests/.keys (ignoradas por Git). No contiene claves privadas de prueba
publicadas. `composer analyse` ejecuta Larastan/PHPStan nivel 5 sin baseline; Pint aplica
el preset Laravel. El lockfile resuelve dependencias para PHP 8.3 mínimo.

Las pruebas integradas intercambian códigos mediante Passport/League reales. Las pruebas
del callback aislado simulan HTTP para comprobar rechazo antes de login. `tools/e2e.php`
añade tres servidores HTTP reales y un cookie jar, sin simular el OP. Comprueba login único,
identidad compartida, Gate/Policy y ACL independiente, conservación del esquema de Auth
local y ausencia de copia de contraseña central, claves y
bases distintas, CSRF en formularios, logout y entrega back-channel.

La suite negativa cubre firma alterada, kid desconocido, alg none/HS256, aud/azp/issuer,
expiración, nonce, PKCE erróneo/plain, callback inválido, código repetido y cuenta suspendida.
Otras pruebas cubren consentimiento de un uso, caducidad de sesión, rotación real de claves,
Logout Tokens inválidos, duplicados y reintento tras HTTP 503.

Para ejecutar localmente:

```sh
composer install
composer test
composer test:conformance
composer lint
composer analyse
composer validate --strict
composer audit --locked
php tools/setup-examples.php
php tools/e2e.php
```

E2E requiere puertos loopback 9100/9101/9102 libres, proc_open y PDO SQLite. Se niega a
reutilizar servidores ajenos y detiene únicamente los procesos que inicia. El setup es
repetible, conserva bases y secretos, y E2E retira su identidad temporal al terminar.
La versión actual prueba también pérdida de la cookie de sesión central, rotación de
Recordarme, confirmación de contraseña, cierre de otro navegador y tres entregas de logout.

Resultado local tras auditoría/operaciones (2026-11-14): **209 tests / 982 aserciones** en
PHP 8.3.36, 8.4.27 y 8.5.12, todos aprobados; E2E, Pint y PHPStan también aprobados.
No cambiaron dependencias: validate/audit de Composer conservan la consulta del 2026-11-13.
La consolidación del esquema añade `MigrationTest`: crea, revierte y recrea las siete
tablas del servidor desde un solo archivo; comprueba PKCE por defecto, campos opcionales
e índices. Las repeticiones están en `.runtime/conformance/consolidated-schema-php*-verified.log`.
La fase de bases añade limpieza, eventos después de commit, expiración local y comparación
sensible a mayúsculas; la fase Recordarme añade rotación, replay, autenticación fresca y
administración de dispositivos. Auditoría/operaciones suma 25 casos nuevos y aserciones
de eventos en el grant real: rollback, exclusión de secretos, instalación repetida, migración
en DB aislada, claves, múltiples errores y JSON. Logs actuales: `.runtime/audit-php83.log`,
`audit-php84.log`, `audit-php85.log`, con sus JUnit en el mismo directorio privado.
La configuración `phpunit.database.xml` ejecuta toda la suite más catorce casos concurrentes
y la comprobación de entorno: MySQL 8.4.13, **224 pruebas / 1055 aserciones**;
PostgreSQL 18.7, **224 / 1053**, sin fallos ni saltos. Ver [reproducción y límites](DATABASE-CONCURRENCY.md)
y [Recordarme/dispositivos](REMEMBER-DEVICES.md).
`phpunit.xml` excluye `tests/Database`: esos casos requieren motores reales y pcntl, no
se sustituyen por simulaciones ni se cuentan como saltos de la suite SQLite.
El subconjunto normativo propio suma 106 tests / 414 aserciones. Su lanzador genera JUnit
y JSON en .runtime/conformance, sin publicación. Los cinco planes del harness oficial se ejecutan localmente con Docker/WSL y TLS privado. Ver
[estado de instalación](OIDF-WINDOWS-SETUP.md) y [alcance](OIDC-CONFORMANCE.md).
La revisión de observaciones terminó con 58 PASSED, 0 WARNING/FAILED, 16 REVIEW auditados
localmente y 10 SKIPPED contrastados con discovery y pruebas negativas. El informe
sin secretos está en conformance/oidf-review.json. Los estados oficiales se conservan.
En Windows composer analyse usa tools/analyse.php con --debug para evitar el bloqueo de
manifiestos Testbench entre procesos. No modifica vendor ni reduce reglas/nivel de análisis.
Ejecuta PHPStan y PHPUnit en serie: una ejecución simultánea produjo 17 errores de rename
de services.php en Windows. El intento se conservó; la repetición normativa en serie pasó
106 tests / 414 aserciones, registrada en `.runtime/remember-own-conformance-verified.log`.
La repetición de esta fase también pasó 106 / 414: `.runtime/audit-own-conformance.log`.
Pint pasó 136 archivos (`.runtime/audit-pint.log`) y Larastan nivel 5 sin baseline
(`.runtime/audit-analyse.log`). E2E pasó y ejecutó el doctor OP y ambos RP contra
discovery/JWKS reales: `.runtime/audit-e2e.log` y `.runtime/operations-e2e-diagnostics.json`.
Instaladores, catálogo y garantías: [auditoría y operaciones](AUDIT-OPERATIONS.md).

La fase posterior repitió 209 / 982 en PHP 8.3/8.4/8.5, 106 / 414 normativos, Larastan,
Pint (145 archivos) y E2E, todo aprobado. Informes actuales `.runtime/deployment-*`.
Además verificó dos despliegues optimizados HTTPS, 60 controles Chromium/Firefox/WebKit,
doce de proxy y **1805,85 s / 15 850 solicitudes / 8 usuarios / cero errores inesperados**.
Outbox vacías después de una pausa de 120 s; se conservó el intento fallido anterior y
se repitió la campaña completa. Ver [reproducción, métricas y alcance](DEPLOYMENT-VALIDATION.md)
y [JSON sin secretos](../conformance/deployment-results.json).

Tras integrar la rama mediante fast-forward, GitHub Actions ejecutó la matriz en **main**,
commit `7553a96`, Ubuntu 26.04.1: **seis trabajos aprobados**, run `43674756937`,
2026-11-15 03:59:30–04:02:12 UTC (14 de noviembre en
Cancún). Cada PHP 8.3.36/8.4.27/8.5.12 pasó 209 / 982 generales, 106 / 414 normativos,
Pint (137 archivos), Larastan, validate/install/audit y E2E. La consulta audit de esa
ejecución no encontró avisos conocidos. MySQL 8.4.13 pasó 224 / 1055 y PostgreSQL 18.7
224 / 1053; el trabajo de manifiestos también pasó.

La ejecución previa pasó con seis advertencias por checkout@v4/Node 20. Tras actualizar
la acción a v7.0.1 fijada por SHA y aprobar actionlint 1.7.12, se subió la corrección a
`codex/ci-checkout-node24` con autorización y se validó antes de integrar. Los seis logs
de la nueva matriz de main confirman
el SHA fijado y **cero advertencias del runner**, sin fallos ni pruebas saltadas.
Ver [evidencia remota y reproducción](CI-REMOTE.md)
y [JSON sin secretos](../conformance/ci-results.json).

La fase de compatibilidad posterior se integró en **main** por fast-forward y pasó
**14/14 trabajos**, run `43680036096`, commit `3f55235`, UTC 2026-11-15
05:12:00–05:14:44. PHP 8.3/8.4/8.5 × locked/lowest/latest:
**212 / 994 generales y 106 / 414 normativos por combinación**, plataforma, audit y E2E
aprobados. Pint (140 archivos) y Larastan se verifican con las herramientas conservadas
en locked. Las resoluciones alternativas usan el PHP real de cada job y mantienen los
controles de seguridad. El lockfile principal no cambió.

MySQL 8.4.13 y PostgreSQL 18.7 pasaron tanto READ COMMITTED como REPEATABLE READ:
**228 / 1070** por perfil MySQL y **228 / 1068** por perfil PostgreSQL. Los hijos verifican
el aislamiento real al reconectar. Tres casos nuevos prueban reintentos, rollback y
callbacks sólo después de commit; otro caso SQL evita reintentar restricciones únicas
ajenas a la identidad. Se conservan las catorce carreras reales.
El primer run falló en tres trabajos; la repetición completa tras corregirlos pasó sin
advertencias del runner. CI conserva trece artifacts de lockfiles, versiones y JUnit por
siete días. Ver [informe y límites](COMPATIBILITY.md) y
[resumen estructurado](../conformance/compatibility-results.json).

Siguen fuera del cierre otras versiones/aislamientos, navegadores comerciales/móviles
físicos y carga distribuida/multijornada. Los navegadores Linux y el harness OIDF conservan
su evidencia local; esta CI no los ejecutó. No hay certificación oficial.
