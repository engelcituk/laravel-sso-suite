# Pruebas y evidencia

`composer test` ejecuta PHPUnit/Orchestra Testbench con Laravel real, SQLite y claves RSA
efímeras generadas en tests/.keys (ignoradas por Git). No contiene claves privadas de prueba
publicadas. `composer analyse` ejecuta Larastan/PHPStan nivel 5 sin baseline; Pint aplica
el preset Laravel. El lockfile resuelve dependencias para PHP 8.3 mínimo.

Las pruebas integradas intercambian códigos mediante Passport/League reales. Las pruebas
del callback aislado simulan HTTP para comprobar rechazo antes de login. `tools/e2e.php`
añade tres servidores HTTP reales y un cookie jar, sin simular el OP. Comprueba login único,
identidad compartida, Gate/Policy y ACL independiente, ausencia de contraseñas RP, claves y
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

Resultado local final (2026-11-14): 155 tests / 635 aserciones en PHP 8.3.36, 8.4.27 y 8.5.12, todos
aprobados; E2E, Pint, PHPStan, validación Composer y auditoría también aprobados.
La consolidación del esquema añade `MigrationTest`: crea, revierte y recrea las cinco
tablas del servidor desde un solo archivo; comprueba PKCE por defecto, campos opcionales
e índices. Las repeticiones están en `.runtime/conformance/consolidated-schema-php*-verified.log`.
La fase de bases añade limpieza, eventos después de commit, expiración local y comparación
sensible a mayúsculas. Logs actuales: `.runtime/database-lab-php*-final.log`.
La configuración `phpunit.database.xml` ejecuta toda la suite más diez casos concurrentes
y la comprobación de entorno: MySQL 8.4.13, **166 pruebas / 691 aserciones**;
PostgreSQL 18.7, **166 / 689**, sin fallos ni saltos. Ver [reproducción y límites](DATABASE-CONCURRENCY.md).
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

GitHub Actions define PHP 8.3/8.4/8.5 y las mismas verificaciones. Configurar una matriz no
demuestra que se ejecutó: los entornos verificados figuran en ROADMAP. Quedan campañas de
carga prolongada, otros aislamientos/versiones y navegadores reales con políticas cross-site.
MySQL/PostgreSQL y la suite OpenID Foundation sí se ejecutaron localmente. No hay certificación oficial.
