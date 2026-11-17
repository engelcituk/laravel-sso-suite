# SSO opcional y Auth local

**Integración actual:** las rutas convencionales de Auth en `web` se integran ahora
automáticamente. Las instrucciones manuales y resultados de CI de esta página describen
la fase original. Usa [la guía actual](INSTALL-CLIENT.md) y [sus nuevas evidencias](AGNOSTIC-VALIDATION.md).
La plantilla nueva del cliente incorpora además [alta greenfield al primer login](GREENFIELD-PROVISIONING.md),
con creación de cuentas nuevas; las garantías sobre cuentas existentes se mantienen.

La [preparación posterior del piloto](PILOT-PREPARATION.md) repite la validación operativa
del modo opcional. Sus resultados locales son independientes de la integración y CI
históricas que se documentan en esta página.

Integrado en **main** desde `codex/optional-client-auth` mediante fast-forward autorizado,
de `848b35cce1eaaa7a20afbd246c9b263a81cbde3b` a
`249ca73178df67c1804cfd1c0ef5e1ca298d547e`, sin conflictos ni reescribir commits.
Se conserva la rama de origen. No se publicaron tags, releases ni versiones.

## Comportamiento

| Configuración | Autenticación y persistencia |
| --- | --- |
| `SSO_CLIENT_ENABLED=false` (predeterminado) | Auth nativo del anfitrión; no requiere tablas SSO ni contacto con el OP para login/recursos locales; endpoints SSO 404 |
| `SSO_CLIENT_ENABLED=true` | Login OIDC con S256; rutas nativas integradas redirigen GET o rechazan POST/JSON; recursos integrados exigen sesión SSO vinculada |
| Cambio de modo | Se exige nueva autenticación; una sesión de otro origen no concede acceso |
| OP no disponible | La política SSO falla cerrada; no activa Auth local automáticamente |

El anfitrión conserva su User, tabla, hashes, remember_token, perfil, ID, relaciones y
permisos. El paquete no modifica su esquema ni escribe esos atributos. Las altas nuevas
requieren una fábrica explícita del anfitrión; no se copia la contraseña central.
Una identidad existente nunca se asocia por coincidencia de correo ni cambia de dueño
implícitamente. El logout SSO usa `logoutCurrentDevice()` para preservar el recaller nativo.

La única migración cliente define `sso_identities`, `sso_local_sessions` y
`sso_logout_receipts`. El servidor mantiene su única migración y contracts ninguna.
`sso_identities` guarda issuer/subject únicos y la clase/clave del usuario local. La
sesión SSO también verifica ese propietario, evitando reutilizarla con otra cuenta local.
El modelo y las tablas propias usan la misma conexión para conservar la transacción.

Integra una vez `sso.local` en login/registro/reset/logout nativos, después de `web` y
antes de `guest`, y `auth` + `sso.session` en recursos protegidos. Configura el guard,
modelo, redirección de invitados y login local correctos. El paquete no reescribe rutas
de Breeze/Fortify ni intercepta rutas del anfitrión que no usen esos middleware.
Después basta cambiar el flag y reconstruir configuración/reiniciar procesos persistentes
según el despliegue. [Instalación y ejemplos completos](INSTALL-CLIENT.md).

## Resultados ejecutados

[Run 44007989216 de main](https://github.com/engelcituk/laravel-sso-suite/actions/runs/44007989216),
commit `249ca73178df67c1804cfd1c0ef5e1ca298d547e`, **14/14 trabajos aprobados**.
UTC 2026-11-17 03:07:58–03:10:47; 16 de noviembre 22:07:58–22:10:47 en Cancún.
Los catorce logs de esta ejecución se revisaron por separado. No aparecieron fallos ni
advertencias del runner y no hicieron falta correcciones adicionales para la integración.
Los timestamps se conservan tal como los informa GitHub. La actualización de este informe
es posterior al commit probado y activa otra CI en main.

Antes de integrar pasaron los runs de rama
[43991616405](https://github.com/engelcituk/laravel-sso-suite/actions/runs/43991616405)
(`b385141`, corrección) y
[43993055348](https://github.com/engelcituk/laravel-sso-suite/actions/runs/43993055348)
(`249ca73`, documentación), ambos 14/14. Esas ejecuciones conservan su alcance histórico;
la tabla siguiente corresponde a la nueva ejecución en main.

| Evaluación remota | Resultado real |
| --- | --- |
| PHP 8.3.36 / 8.4.27 / 8.5.12 × locked/lowest/latest | 230 pruebas / 1076 aserciones por perfil; nueve aprobados |
| Conformidad PHPUnit propia, mismos nueve perfiles | 106 / 414 por perfil; aprobada, incluida en la suite general, no sumable como casos distintos |
| E2E HTTP, mismos nueve perfiles | Doctores OP y dos RP, discovery/JWKS, SSO/ACL, Recordarme/dispositivos, CSRF, logout y back-channel aprobados |
| MySQL 8.4.13, READ COMMITTED y REPEATABLE READ | 246 / 1152 por perfil; catorce carreras con procesos reales y entorno verificado |
| PostgreSQL 18.7, READ COMMITTED y REPEATABLE READ | 246 / 1150 por perfil; catorce carreras con procesos reales y entorno verificado |
| Pint y Larastan nivel 5 sin baseline | Aprobados con herramientas locked en los tres PHP; Pint 147 archivos |
| Composer validate/platform/audit | Aprobados; audit no encontró avisos conocidos en las nueve consultas |
| Manifiestos | Siete validados; trabajo aprobado |
| Advertencias del runner | Cero en los catorce logs inspeccionados; los catorce trabajos terminaron correctamente |

`lowest` resolvió Laravel 13.40.0, Passport 13.7.1 y League 9.2.0; locked/latest
resolvieron Laravel 13.42.0, Passport 13.10.0 y League 9.4.1. Las versiones, hashes de
lockfiles/inventarios y entornos por trabajo están en el
[resumen estructurado](../conformance/optional-auth-results.json). El lockfile principal
no se modificó. CI guardó trece artifacts temporales con entorno/lock/inventario/JUnit
y retención de siete días; el resumen versionado no contiene secretos ni logs crudos.

Los 18 casos de `OptionalClientTest` verifican Auth local sin ninguna tabla SSO/HTTP,
rechazo de credenciales locales al activar SSO, aislamiento de origen de sesión,
conservación de usuario y ACL, vinculación explícita/idempotente sin reasignación,
ausencia de email linking, fábrica obligatoria, rollback, doctor desactivado,
callback/logout, migración up/down sin alterar users, SsoOnly opt-in y morph map ajeno.
Las pruebas PHPUnit de rutas arrancan con el flag apagado y luego lo activan: verifican el
gating en ejecución. La campaña posterior con config/route cache, reinicios y ambos modos
está documentada por separado en [preparación del piloto](PILOT-PREPARATION.md).
El E2E utiliza instalaciones nuevas de ejemplos con password/remember_token normales y
comprueba que la contraseña central no se copió a los RP.

## Fallos encontrados y corregidos

1. En MySQL REPEATABLE READ, una carrera podía leer la vinculación recién confirmada
   pero buscar su usuario con un snapshot anterior. La resolución ahora lee el propietario
   con `lockForUpdate`, sin actualizar atributos del usuario. La repetición local pasó:
   MySQL **245 / 1150**, PostgreSQL **245 / 1148**, antes de añadir el último caso de morph map.
   La matriz remota posterior incluye ese caso y pasa los cuatro perfiles SQL indicados arriba.
2. El [primer run 43684419416](https://github.com/engelcituk/laravel-sso-suite/actions/runs/43684419416)
   pasó SQL/manifiestos y falló en los nueve E2E PHP: el cursor de la nueva inspección de
   password seguía reteniendo una lectura SQLite e impedía escribir el back-channel.
   `tools/e2e.php` cierra explícitamente el cursor antes del logout. La repetición completa
   del run 43991616405 pasó, incluido drenaje de entregas en los nueve E2E.

Se conservaron los intentos fallidos. No se desactivaron checks de plataforma, auditoría,
análisis estático o pruebas para conseguir el resultado. Las validaciones locales de
PHP se realizaron en serie con Larastan para evitar carreras de manifiestos Testbench
en Windows; los laboratorios Docker utilizan snapshots privados.

## Bases existentes y alcance

Una migración por paquete se conserva consolidando el esquema inicial, no mediante un
archivo incremental. Laravel no reaplica migraciones registradas. Para una instalación
previa, revisa con respaldo la adaptación de las tablas propias y la vinculación de
cuentas mediante iss/sub verificados. No uses `migrate:fresh` sobre datos del anfitrión.
El paquete no elimina columnas antiguas, no añade contraseñas a usuarios que no las
tenían y no migra silenciosamente relaciones. Las bases locales existentes de los
ejemplos no se han reseteado en esta fase.

Los usuarios nuevos creados por la fábrica de demostración no conocen una contraseña
local: si se desea habilitarla después, el anfitrión debe ofrecer su proceso de
establecimiento/recuperación. Las cuentas locales ya existentes conservan su hash.
Las fábricas y listeners son código del anfitrión; controla sus privilegios y efectos externos.

Durante esta integración no se repitieron la suite oficial OIDF, los navegadores cross-site
ni la carga de 30 minutos. La [preparación posterior](PILOT-PREPARATION.md) registra las
repeticiones con sus propias huellas; los resultados históricos se conservan.
No hay certificación ni evaluación externa. La integración en main no cambia ese alcance.
La siguiente fase posible es un piloto en un entorno concreto de destino y una revisión
independiente; publicar versiones sigue requiriendo autorización expresa.
