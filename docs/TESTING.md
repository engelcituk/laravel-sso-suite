# Pruebas y evidencia

La ampliación de [gestión de clientes](CLIENT-REGISTRATION-SERVICE.md) pasa **320 pruebas /
1677 aserciones** en PHP 8.3/8.4/8.5. Añade **21 / 142** casos para listado, edición,
revocación, CRUD con Gate, Artisan, secretos, rollback, conservación de cuentas y OIDC real.
En cada uno de los cuatro perfiles SQL pasan **29 / 180**: esos 21 casos, cuatro carreras
nuevas (editar/revocar antes o después del canje) y cuatro regresiones de replay,
retirada de asignación y revocación global. La barrera del replay ahora se coloca antes
de entrar al canje, porque la emisión se serializa por aplicación con su administración.
No se presenta este filtro como toda la matriz SQL histórica.
También pasan **106 / 414** OIDC propias, **53 controles HTTP**, Larastan nivel 5 sin
baseline, Pint global con caché nueva, siete manifiestos/plataforma/auditoría Composer y
tres doctores. Los diez originales protegidos y las dos migraciones conservan hashes;
la fuente del contenedor SQL coincide y sus servicios quedan detenidos.
[Resultados y hashes](../conformance/client-management-service-results.json).

Los intentos iniciales conservados detectaron errores en los tests: se esperaba 200 en
`/oidc/session` con un token revocado (corresponde 401), se reutilizó una cabecera Bearer
en `/oidc/token`, se creó otro sujeto en un helper y se comparó `last_seen` después de
un UserInfo legítimo. Se corrigieron esas expectativas y el aislamiento del test.
Larastan exigió usar el modelo Passport en `ModelNotFoundException` y acceder a sus
atributos con `getAttribute`; el resultado final no añade ignores ni baseline.
No se repitieron campañas OIDF oficiales, navegador cross-site ni carga prolongada.

La extracción posterior de [creación de clientes](CLIENT-REGISTRATION-SERVICE.md)
añade 18 casos para el contrato público, su adaptador Artisan, un controlador anfitrión
con Gate, secretos, validación y transacciones sin cambios de usuarios/entorno.
[Evidencia de esa extracción](../conformance/client-registration-service-results.json).
Pasaron **299 / 1535** por PHP 8.3/8.4/8.5, **106 / 414** OIDC propias,
los **18 / 101** nuevos en cuatro perfiles SQL y **53 controles HTTP**. Larastan,
Pint global, Composer y tres doctores aprobaron; migraciones y originales intactos
respecto al inicio de esta extracción. Las campañas anteriores conservan su alcance.

La fase posterior de [sistemas y cuentas centrales](CENTRAL-ACCOUNTS.md) añade 17 casos
generales para registro, invitación, asignaciones y recuperación, además de dos carreras
SQL: consumo único del token de recuperación y retirada durante la emisión OAuth.
Conserva el fallo inicial PostgreSQL RR y su corrección mediante serialización de la
inscripción. El E2E amplía el recorrido con correo en log privado, sin envíos externos.
Pasaron **281 / 1434** por PHP 8.3/8.4/8.5, **106 / 414** OIDC propias, **53 controles HTTP**
y **2 / 9** por cada uno de los cuatro perfiles SQL. Larastan, Pint global, Composer
y los doctores también aprobaron. No equivale a repetir las campañas históricas completas.
[Resultados actuales con hashes](../conformance/central-accounts-results.json).

Alta greenfield posterior: **264 / 1300** en PHP 8.3/8.4/8.5, grupo OIDC propio
**106 / 414**, Pint global y Larastan aprobados. Añade doce casos de primer login,
colisiones, validación previa y conservación de cuentas; el E2E pasa **37 controles**
con un cliente sin usuarios ni vínculos previos. Dos carreras SQL nuevas pasan en
MySQL/PostgreSQL con READ COMMITTED y REPEATABLE READ (**2 / 7** por perfil).
[Contrato, intentos y alcance](GREENFIELD-PROVISIONING.md),
[JSON de esta fase](../conformance/greenfield-client-results.json). Las ejecuciones que
siguen conservan sus fuentes originales y describen las fases anteriores.

Integración agnóstica del 2026-11-17: **252 / 1209** generales en PHP 8.3/8.4/8.5,
**106 / 414** propias de OIDC, Pint global con caché nueva, Larastan y controles Composer
aprobados. Añade 22 casos y `tools/agnostic-e2e.php`: **32 controles HTTP** en servidor
limpio y clientes nuevo/existente, con config/route cache y ambos modos. No usa bases
originales de ejemplos ni instala frontend. [Alcance y reproducción](AGNOSTIC-VALIDATION.md),
[resultados con hashes](../conformance/agnostic-auth-results.json). Las campañas anteriores
que siguen abajo no se repitieron para estos cambios de comportamiento.

Cierre de formato del 2026-11-17: **Pint global aprobado con caché nueva**, los 37 archivos
corregidos sólo en finales de línea y con tokens PHP equivalentes. PHPUnit **230 / 1076**
en PHP 8.3/8.4/8.5, grupo normativo propio **106 / 414**, Larastan y controles Composer
aprobados. Lockfile, bases y secretos de ejemplos intactos.
[Evidencia y límites](FORMAT-QUALITY.md), [JSON](../conformance/format-quality-results.json).

Preparación posterior del piloto: [informe y alcance](PILOT-PREPARATION.md). Repite OIDC
oficial, navegadores cross-site y ambos modos con cachés/reinicios; distingue sus pruebas
locales de la CI histórica de integración descrita abajo. La campaña completa pasó:
**1806,038 s / 15 832 solicitudes / ocho usuarios / cero errores inesperados**, colas
0/0 al cierre. Los exportadores verificaron fuentes y resultados antes de escribir
los resúmenes sin secretos. En aquella preparación Pint global señaló 37 archivos;
el cierre de formato posterior descrito arriba los corrigió. Sus campañas conservan
las huellas originales y no se atribuyen a una nueva ejecución tras el formato.

Estado del modo opcional: [run 44007989216](https://github.com/engelcituk/laravel-sso-suite/actions/runs/44007989216),
`249ca73`, integrado en **main** por fast-forward autorizado, **14/14 trabajos aprobados**. Por cada uno
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
La prueba PHPUnit de rutas verifica gating en ejecución. La campaña posterior
[de preparación](PILOT-PREPARATION.md) sí ejecuta ambos modos con cachés reales,
reinicios y navegadores. El E2E remoto histórico usa instalaciones nuevas y HTTP loopback.

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
