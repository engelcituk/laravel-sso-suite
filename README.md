# Laravel SSO

Paquetes para agregar **autenticación centralizada con OpenID Connect** a aplicaciones
Laravel. Una aplicación actúa como servidor de identidad y las demás como clientes;
cada cliente conserva sus usuarios locales, sus permisos y su base de datos.

El proyecto anfitrión elige su frontend, Auth y administración. Los paquetes ofrecen
servicios reutilizables y adaptadores HTTP/Artisan, sin exigir un starter kit, una
biblioteca de permisos ni un panel administrativo. Funcionan con Blade, Vue, React e Inertia.

**Estado experimental, pre-alpha.** Laravel 13, PHP 8.3–8.5 y Passport 13 en el servidor.
El cliente no depende de Passport. Licencia MIT. Los nombres `laravel-sso/*` son
provisionales y todavía no hay versiones publicadas en Packagist.

## Empezar

**Empieza aquí: [guía rápida — del servidor al cliente](docs/QUICKSTART.md).**
El recorrido prepara dos proyectos Laravel nuevos, registra una aplicación, crea una
cuenta central y comprueba el primer login. Mientras no exista una versión publicada,
la instalación utiliza un repositorio Composer `path` apuntando a una copia local.

| Necesidad | Guía |
| --- | --- |
| Preparar el servidor de identidad | [Instalación del servidor](docs/INSTALL-SERVER.md) |
| Conectar una aplicación cliente | [Instalación del cliente](docs/INSTALL-CLIENT.md) |
| Crear cuentas y asignar acceso a aplicaciones | [Cuentas y acceso central](docs/CENTRAL-ACCOUNTS.md) |
| Crear, listar, editar y revocar clientes desde un CRUD o Artisan | [Servicios de gestión de clientes](docs/CLIENT-REGISTRATION-SERVICE.md) |
| Integrar un frontend o Auth propios | [Integración sin stack obligatorio](docs/STACK-AGNOSTIC.md) |

El escenario principal son instalaciones nuevas (**greenfield**). En un cliente nuevo,
el primer login SSO válido crea y vincula la cuenta local con el esquema Laravel
convencional. Los siguientes logins reutilizan el vínculo sin sincronizar el perfil.
Si el email ya pertenece a una cuenta local sin vincular, el acceso se rechaza: la
vinculación de cuentas existentes requiere una verificación explícita del operador.

Con SSO activo, el servidor autentica las cuentas centrales. Cada cliente conserva su
usuario Eloquent y sus Gates/Policies; la vinculación `(iss, sub)` vive en tablas propias.
`SSO_CLIENT_ENABLED=false` permite continuar con Auth local. El paquete no modifica
el esquema de `users`, los usuarios existentes, sus contraseñas ni sus permisos. Consulta [integración de los dos modos](docs/INSTALL-CLIENT.md)
y [resultados de la fase](docs/OPTIONAL-AUTH.md).
El cliente no depende de Passport. No hay dependencias de frontend ni ACL obligatoria.
El servidor funciona en un Laravel limpio: ofrece login, registro y recuperación
opcionales mediante formularios o JSON; el registro público está cerrado por defecto.
Las aplicaciones nuevas exigen asignación explícita de cuentas centrales.
Si ya existe login del anfitrión, el servidor lo conserva. El cliente descubre el guard/modelo de Auth
e integra las rutas convencionales. Blade, Vue, React e Inertia pueden usar el mismo backend.
[Guía detallada de stacks, proyectos nuevos y existentes](docs/STACK-AGNOSTIC.md) y
[evidencia local de esta integración](docs/AGNOSTIC-VALIDATION.md).
El [alta greenfield de primer login](docs/GREENFIELD-PROVISIONING.md) usa el modelo Laravel
convencional, crea sólo cuentas nuevas y conserva las políticas explícitas de configuraciones anteriores.

## Capacidades y estado

Incluye Authorization Code + PKCE S256, consentimiento, discovery, JWKS con convivencia de
claves, ID Tokens RS256, UserInfo, callback con state/nonce y aprovisionamiento configurable.
El perfil Code admite GET/POST, nonce/state opcionales para clientes OIDC externos, query/form_post,
prompts combinados, max_age, Basic y at_hash. Consulta [alcance de conformidad](docs/OIDC-CONFORMANCE.md)
y [resultados reales](docs/OIDC-CONFORMANCE-RESULTS.md).
Hay sesiones centrales con caducidad, comprobación remota con fallo cerrado, logout con
confirmación, back-channel firmado y notificaciones durables con reintentos.

La integración está probada con tres aplicaciones y hosts independientes. No hay
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

## Calidad y documentación técnica

```sh
composer validate --strict
composer test
composer lint
composer analyse
composer audit --locked
```

Consulta el [índice completo de documentación](docs/README.md), la
[guía de pruebas](docs/TESTING.md) y el [roadmap con evidencias y pendientes](docs/ROADMAP.md).
Los informes de CI, SQL, OIDC, navegadores y carga identifican las fuentes y fechas
probadas; no acreditan automáticamente cambios posteriores.

En Windows, si OpenSSL no encuentra su configuración, define `OPENSSL_CONF` con la ruta
`extras/ssl/openssl.cnf` de tu PHP. Usa un binario PHP cuyas extensiones correspondan a su
versión. Ver [diagnóstico](docs/TROUBLESHOOTING.md) para los detalles del entorno probado.
