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

Resultado local final: 97 tests / 386 aserciones en PHP 8.3.36, 8.4.27 y 8.5.12, todos
aprobados; E2E, Pint, PHPStan, validación Composer y auditoría también aprobados.
El subconjunto normativo propio suma 63 tests / 259 aserciones. Su lanzador genera JUnit
y JSON en .runtime/conformance, sin publicación. El harness oficial está descargado y
su preflight bloqueado por Docker: [alcance y reproducción](OIDC-CONFORMANCE.md).
En Windows composer analyse usa tools/analyse.php con --debug para evitar el bloqueo de
manifiestos Testbench entre procesos. No modifica vendor ni reduce reglas/nivel de análisis.

GitHub Actions define PHP 8.3/8.4/8.5 y las mismas verificaciones. Configurar una matriz no
demuestra que se ejecutó: los entornos verificados figuran en ROADMAP. MySQL/PostgreSQL,
concurrencia bajo carga, navegadores reales con políticas cross-site y la suite OpenID
Foundation siguen siendo pasos de validación adicionales. No hay certificación oficial.
