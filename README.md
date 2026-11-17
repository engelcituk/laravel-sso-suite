Laravel SSO Suite
One identity. Multiple Laravel applications. Independent permissions.

Open-source Single Sign-On solution for Laravel, providing centralized authentication through OAuth2/OpenID Connect, local user provisioning, application-specific authorization, and global session management.

# Laravel SSO

Bibliotecas para **extender aplicaciones Laravel anfitrionas** con funciones SSO.
Tu proyecto conserva su Auth, modelos, CRUD, políticas y frontend. `server` y `client`
identifican el papel que agregas a la aplicación, no aplicaciones independientes.
Los [servicios públicos y el ejemplo para tu CRUD](docs/CLIENT-REGISTRATION-SERVICE.md)
permiten crear, listar, editar y revocar clientes sin ejecutar Artisan desde un controlador.

**Empieza aquí: [guía rápida — del servidor al cliente](docs/QUICKSTART.md).**
Instalación con comandos Artisan, sin starter kit obligatorio y con una prueba final de login.
También puedes ir directo a [instalar el servidor](docs/INSTALL-SERVER.md) o
[instalar el cliente](docs/INSTALL-CLIENT.md).

Para administrar todo desde Identidad —crear “Nóminas ISR”, sus cuentas y quién puede
entrar— sigue [Sistemas, usuarios y recuperación central](docs/CENTRAL-ACCOUNTS.md).
Incluye alta manual con Artisan, registro público opcional y recuperación por correo,
con JSON y formularios opcionales. Las aplicaciones nuevas admiten sólo usuarios asignados.

**Enfoque principal: proyectos greenfield.** Un Laravel nuevo se prepara desde el inicio
como servidor central; los demás proyectos nacen como clientes de ese servidor.
La integración con aplicaciones existentes es compatibilidad adicional, no un requisito
de adaptación universal. El frontend sigue siendo elección de cada proyecto.

En el cliente, instalar y activar también requiere registrar la aplicación en el servidor,
configurar sus credenciales. Con la configuración nueva, el primer login SSO válido
crea y vincula la cuenta local automáticamente, incluso con `users` vacía, sin fábrica
propia ni `sso:link-user`. Los siguientes logins reutilizan el vínculo sin cambiar la cuenta.
Si un email ya pertenece a una cuenta sin vincular, se rechaza el alta; nunca se adopta por email.

Monorepo **experimental, pre-alpha** de identidad centralizada para Laravel 13 y PHP 8.3+.
Implementación OIDC original sobre la persistencia OAuth de Passport 13 y League OAuth2 Server.
Licencia MIT. Nombres `laravel-sso/*` provisionales; **todavía no están publicados en Packagist**.

Con SSO activo, el servidor autentica las cuentas centrales. Cada cliente conserva su
usuario Eloquent y sus Gates/Policies; la vinculación `(iss, sub)` vive en tablas propias.
`SSO_CLIENT_ENABLED=false` permite continuar con Auth local. El paquete no modifica
el esquema de `users`, los usuarios existentes, sus contraseñas ni sus permisos. Consulta [integración de los dos modos](docs/INSTALL-CLIENT.md)
y [resultados de la fase](docs/OPTIONAL-AUTH.md).
El cliente no depende de Passport. No hay dependencias de frontend ni ACL obligatoria.
El servidor funciona en un Laravel limpio: ofrece login opcional y backend JSON;
si ya existe login del anfitrión, lo conserva. El cliente descubre el guard/modelo de Auth
e integra las rutas convencionales. Blade, Vue, React e Inertia pueden usar el mismo backend.
[Guía detallada de stacks, proyectos nuevos y existentes](docs/STACK-AGNOSTIC.md) y
[evidencia local de esta integración](docs/AGNOSTIC-VALIDATION.md).
El [alta greenfield de primer login](docs/GREENFIELD-PROVISIONING.md) usa el modelo Laravel
convencional, crea sólo cuentas nuevas y conserva las políticas explícitas de configuraciones anteriores.

## Qué funciona y qué falta

Incluye Authorization Code + PKCE S256, consentimiento, discovery, JWKS con convivencia de
claves, ID Tokens RS256, UserInfo, callback con state/nonce y aprovisionamiento configurable.
El perfil Code admite GET/POST, nonce/state opcionales para RPs externos, query/form_post,
prompts combinados, max_age, Basic y at_hash. Consulta [alcance de conformidad](docs/OIDC-CONFORMANCE.md)
y [resultados reales](docs/OIDC-CONFORMANCE-RESULTS.md).
Hay sesiones centrales con caducidad, comprobación remota con fallo cerrado, logout con
confirmación, back-channel firmado y notificaciones durables con reintentos.

La primera vertical está probada con tres aplicaciones y hosts independientes. No hay
certificación OpenID, auditoría externa ni validación operativa para producción. Recordarme
central, dispositivos, eventos de auditoría e instaladores/diagnósticos están implementados
y probados, incluidas carreras en MySQL/PostgreSQL. El alcance y lo pendiente figuran en
[ROADMAP](docs/ROADMAP.md).
No se admite refresh OIDC: al expirar el access token se repite una redirección silenciosa
si la sesión central sigue activa. No se anuncia un grant que no esté habilitado.

## Probar los ejemplos del repositorio

Esta sección ejecuta el laboratorio de ejemplo. Para integrarlo en tus propias
aplicaciones, sigue la [guía rápida de instalación](docs/QUICKSTART.md).

Requisitos: PHP 8.3–8.5, Composer 2, OpenSSL, mbstring, PDO SQLite, DOM y extensiones
habituales de Laravel. La instalación raíz incluye Testbench y Laravel para el laboratorio.

```sh
composer install
composer test
composer test:conformance
php tools/setup-examples.php
php tools/e2e.php
```

Setup crea tres bases SQLite y tres APP_KEY distintas, genera claves OAuth/OIDC y registra
dos clientes confidenciales. Muestra una contraseña aleatoria de desarrollo únicamente al
crear `demo@example.test`. No borra datos ni reemplaza secretos existentes. Los `.env`,
claves, bases y logs quedan ignorados por Git. E2E inicia y detiene sus propios procesos,
usa otra identidad efímera y no requiere la contraseña demo. Deja los registros de
protocolo y auditoría para inspección; no debe ejecutarse contra entornos reales.

Para explorar manualmente, ejecuta cada comando en una terminal distinta:

```sh
php -S 127.0.0.1:9100 -t examples/identity-server/public examples/identity-server/public/index.php
php -S 127.0.0.2:9101 -t examples/app-one/public examples/app-one/public/index.php
php -S 127.0.0.3:9102 -t examples/app-two/public examples/app-two/public/index.php
```

Abre [Application One](http://127.0.0.2:9101), luego [Application Two](http://127.0.0.3:9102).
Con los ejemplos en modo SSO, sólo [Identity Server](http://127.0.0.1:9100/login) recibe la contraseña central.
Los hosts loopback distintos mantienen las cookies separadas; no hace falta editar hosts.
En una terminal del servidor central procesa las notificaciones:

```sh
php examples/identity-server/artisan sso:deliver-logouts
```

Los ejemplos usan `sso.session:strict`: cada operación protegida consulta al OP. Un OP caído
produce rechazo de acceso. La alternativa `sso.session` revisa como máximo cada 30 segundos
por defecto. Back-channel marca la sesión revocada antes de la siguiente operación local.

## Paquetes e instalación

| Paquete | Responsabilidad |
| --- | --- |
| `packages/contracts` | Identidad, evento de auditoría y utilidades PHP sin framework |
| `packages/server` | Códigos Passport, OIDC y sesiones centrales |
| `packages/client` | OIDC RP, usuario Eloquent y sesión Laravel |

Consulta [instalación del servidor](docs/INSTALL-SERVER.md) e
[instalación del cliente](docs/INSTALL-CLIENT.md) para consumir los paquetes mediante
repositorios Composer path. Los manifiestos de `examples/*` permiten instalaciones por
aplicación; el laboratorio raíz reutiliza vendor sólo para facilitar las pruebas. Las bases,
APP_KEY, sesiones y autorizaciones siguen siendo independientes.

## Calidad

```sh
composer validate --strict
composer test
composer lint
composer analyse
composer audit --locked
```

[Guía de pruebas](docs/TESTING.md), [evidencia y pendientes](docs/ROADMAP.md),
[matriz CI remota: seis trabajos aprobados con checkout actualizado](docs/CI-REMOTE.md),
[compatibilidad ampliada: dependencias mínimas/últimas y cuatro perfiles SQL](docs/COMPATIBILITY.md),
[concurrencia MySQL/PostgreSQL y retención](docs/DATABASE-CONCURRENCY.md),
[Recordarme y dispositivos](docs/REMEMBER-DEVICES.md),
[auditoría, instalación y diagnóstico](docs/AUDIT-OPERATIONS.md),
[despliegues, navegadores cross-site y carga de 30 min](docs/DEPLOYMENT-VALIDATION.md),
[preparación del piloto con OIDC repetido y ambos modos con cachés](docs/PILOT-PREPARATION.md),
[cierre de Pint global y calidad en PHP 8.3/8.4/8.5](docs/FORMAT-QUALITY.md),
[plan del piloto externo y revisión independiente](docs/EXTERNAL-PILOT-PLAN.md),
[modelo de seguridad](docs/SECURITY-MODEL.md), [arquitectura](docs/ARCHITECTURE.md),
[decisiones y fuentes](docs/DECISIONS.md), [Auth](docs/LARAVEL-AUTH.md),
[ACL local](docs/LOCAL-ACL.md), [sesiones y logout](docs/SESSIONS.md),
[despliegue](docs/DEPLOYMENT.md), [extensión](docs/EXTENDING.md) y
[solución de problemas](docs/TROUBLESHOOTING.md).

En Windows, si OpenSSL no encuentra su configuración, define `OPENSSL_CONF` con la ruta
`extras/ssl/openssl.cnf` de tu PHP. Usa un binario PHP cuyas extensiones correspondan a su
versión. Ver [diagnóstico](docs/TROUBLESHOOTING.md) para los detalles del entorno probado.
