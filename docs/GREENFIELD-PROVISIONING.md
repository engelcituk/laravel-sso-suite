# Greenfield: alta y vínculo al primer login SSO

**En una instalación nueva del cliente no necesitas crear usuarios locales ni una fábrica.**
Instala/configura el paquete, registra la aplicación en el servidor y entra por `/sso/login`.
El primer callback OIDC válido crea la cuenta local y su vínculo. Los siguientes logins
reutilizan esa cuenta sin actualizar sus atributos ni su contraseña.

El servidor central administra sus propias cuentas mediante `sso:user` y asignaciones
por aplicación; también incluye registro público opcional y recuperación. No se crean
personas por instalar. El cliente necesita un servidor operativo, credenciales y una
cuenta autorizada para esa aplicación. [Flujo descendente](CENTRAL-ACCOUNTS.md).
[Recorrido completo de instalación](QUICKSTART.md).

## Instalación nueva

Con el repositorio Composer ya configurado:

```sh
composer require laravel-sso/client:0.1.x-dev laravel-sso/contracts:0.1.x-dev
php artisan sso:install-client --configure --migrate
php artisan sso:doctor
```

Responde issuer, callback, ID y secreto del cliente registrado. La configuración nueva
usa `greenfield` por defecto. No agregues una fábrica ni ejecutes `sso:link-user` para
una persona que todavía no tiene cuenta local.

Conserva el `User` convencional de Laravel y su migración base. El cliente SSO mantiene
su única migración para tablas propias; no agrega columnas a `users`. Deben existir
`name`, `email` con índice único y la columna de contraseña del modelo. `email_verified_at`
es opcional. El modelo debe usar la conexión de las tablas SSO y el guard configurado.
Un esquema con otros campos obligatorios o reglas de negocio propias necesita `automatic`
con una fábrica explícita; el paquete no inventa sus valores ni cambia el esquema.

Protege el recurso mediante `auth` dentro de `web` y entra por `/sso/login`. Puedes comenzar
con cero usuarios locales. Espera una fila en `users` y una en `sso_identities` para esa
identidad. Repite el login: conservará el ID y seguirá existiendo un solo vínculo.

## Datos que se guardan

La identidad debe proceder de un callback que haya validado state, nonce, firma, issuer,
audiencia, tiempos y el mismo subject en UserInfo. La fábrica no recibe tokens ni la
contraseña central.

| Campo local nuevo | Origen y política |
| --- | --- |
| ID | Generado por el modelo/DB local; se ignora cualquier ID remoto de perfil |
| name | Claim de nombre válido; si no viene, utiliza el email |
| email | Claim válido del servidor confiable, normalizado a minúsculas; máximo 255 bytes |
| email_verified_at | Hora actual sólo si `email_verified` es el booleano `true`; no acepta `"true"` ni `1` como verificación |
| password | Hash de un valor aleatorio de 256 bits, descartado inmediatamente; se genera sólo en el INSERT |
| remember_token | No se copia desde el servidor; se conserva el valor predeterminado local |
| Roles, permisos y otros atributos | No se importan ni asignan desde claims |

La columna `password` NOT NULL del Laravel estándar obliga a guardar un valor para la
cuenta nueva. No se entrega una contraseña local, no se copia la central y no se conoce
el valor aleatorio tras generar su hash. **Ningún password de una cuenta existente se
modifica o regenera.** Si posteriormente eliges Auth local, el anfitrión debe ofrecer
un proceso propio de establecimiento de contraseña.

El vínculo se guarda por `(issuer, subject)` y apunta al modelo/ID local. Una cuenta ya
vinculada conserva nombre, email, verificación, hash, remember_token y permisos aunque
los claims cambien en el servidor. Este modo no sincroniza perfiles.

## Colisiones y fallos

- Si existe una cuenta local con ese email sin vínculo, se rechaza el login. También se
  comprueban diferencias de mayúsculas y cuentas ocultas por scopes/soft-delete. No se
  adopta esa cuenta, aunque el servidor afirme que verificó su email.
- Para reutilizar una cuenta existente, un operador debe verificar la persona y ejecutar
  `sso:link-user ID_LOCAL SUJETO_CENTRAL`. No se reasigna un vínculo ya establecido.
- Un email ausente o inválido, un esquema no compatible, una firma inválida o un subject
  incoherente rechaza el acceso. No se fabrican emails para completar datos faltantes.
- Alta y vínculo son transaccionales. Si la política `allowed` deniega el acceso, se
  revierten ambos y no se publica el evento de aprovisionamiento exitoso.
- Un propietario vinculado que ya no existe produce rechazo; no se recrea ni se cambia
  silenciosamente de dueño.
- La reserva única de identidad evita dos cuentas para el mismo issuer/subject. Un email
  único y la normalización evitan que dos altas SSO simultáneas compartan o dupliquen
  esa cuenta. Una colisión concurrente se rechaza sin dejar un vínculo incompleto.

La respuesta pública de rechazo continúa siendo el error genérico SSO; no revela si una
cuenta determinada existe. Revisa issuer/claims, la política y el esquema desde el backend
privado. `sso:doctor --offline` incluye `greenfield_model` y no modifica el esquema.

## Políticas disponibles y actualización

| `sso-client.provision` | Alta local |
| --- | --- |
| `greenfield` | Fábrica incluida para el esquema Laravel convencional; predeterminado de la plantilla nueva |
| `restricted` | Sólo usuarios previamente vinculados por un operador; sin altas |
| `automatic` | Fábrica `create_user` del anfitrión para un esquema/proceso personalizado |

La plantilla nueva lee `SSO_CLIENT_PROVISION`, por ejemplo:

```dotenv
SSO_CLIENT_PROVISION=greenfield
```

El instalador **conserva las configuraciones ya copiadas**. Si tu archivo anterior fija
`'provision' => 'restricted'`, sigue restringido; agregar una variable no cambia un valor
PHP literal. Para adoptar el modo nuevo cambia conscientemente esa clave a `greenfield`
o incorpora la lectura de entorno de la plantilla. No se ejecutan altas al instalar:
sólo cuando llega una identidad nueva mediante login SSO válido.
Si dependías de los valores predeterminados sin copiar configuración, el nuevo valor
predeterminado sí pasa a ser `greenfield`; define `SSO_CLIENT_PROVISION=restricted`
para conservar explícitamente la política anterior antes de actualizar.

`automatic` mantiene su contrato de fábrica obligatoria y `restricted` mantiene el de
vínculo explícito. Los ejemplos antiguos del repositorio seleccionan sus propias fábricas
y no se reconvierten ni se reinicializan sus bases. Las configuraciones personalizadas
siguen bajo responsabilidad del anfitrión, incluidos sus observers y listeners.

Después de cambiar configuración reconstruye su caché y recarga procesos persistentes.
No hay cambio de esquema: una migración servidor, una cliente y ninguna en contracts.
No se publican versiones como parte de este ajuste.

## Evidencia y alcance

Los resultados de este alta greenfield son históricos. La fase posterior de
[cuentas centrales](CENTRAL-ACCOUNTS.md) modifica la migración inicial del servidor
para asignaciones y repite sus propias pruebas. La migración cliente no cambia.

[Resultados locales de esta fase](../conformance/greenfield-client-results.json).
La suite pasó **264 pruebas / 1300 aserciones** en PHP 8.3.36, 8.4.27 y 8.5.12;
el grupo propio OIDC pasó **106 / 414**. Pint global con caché nueva y Larastan nivel 5
pasaron. Se añadieron doce casos generales y dos casos de concurrencia.
Las pruebas nuevas incluyen callback validado, cuenta/vínculo nuevos, repetición,
colisiones, preservación de usuarios anteriores, verificación estricta de email,
rollback y rechazo de JWT/UserInfo inválidos antes del alta.

El E2E crea un cliente con `users` vacía y esquema convencional, sin login local, fábrica
propia ni comando de vinculación. Usa Code/PKCE real y comprueba alta, vínculo y segunda
entrada sin cambios, junto con un cliente anterior restringido cuyos datos se conservan.
Se ejecuta en hosts loopback HTTP desechables, con config/route cache Laravel y OPcache
PHP de desarrollo apagado. Las carreras SQL se ejecutan en el laboratorio aislado, no
en las bases originales.

El E2E pasó **37 controles**. Los doctores del servidor y ambos clientes también
pasaron; el cliente greenfield incluye el control adicional de modelo/índice.
Las dos carreras SQL pasaron en **MySQL y PostgreSQL**, con **READ COMMITTED y
REPEATABLE READ**: dos casos y siete aserciones por perfil, con ocho procesos para
la misma identidad y dos para identidades distintas con el mismo email. La huella
de fuentes de paquetes/pruebas en el contenedor coincide con el workspace.

Se conserva un intento SQL fallido de MySQL REPEATABLE READ: la barrera del harness
esperaba después de una consulta de email que el bloqueo de índices podía serializar.
Se movió antes de la reserva y se repitieron los cuatro perfiles; no se cambió código
del paquete para resolver ese timeout. También se conservan los primeros intentos
unitarios que corrigieron un nombre de helper reservado por PHPUnit y un fake HTTP
que reutilizaba la respuesta del login anterior.

Para repetir la prueba general y el login HTTP desde el repositorio:

```sh
composer test
composer test:conformance
composer lint
composer analyse
php tools/agnostic-e2e.php
```

El harness usa sólo `.runtime`, puertos loopback 9300/9301/9302 y sus propios procesos.
Para repetir las carreras usa `tools/database-lab.ps1` con el filtro
`ConcurrencyTest::test_greenfield_` en ambos motores y aislamientos. Sus bases son
descartables; no ejecutes estos fixtures contra un servidor de aplicación.

Las validaciones de Vue/React/Inertia, navegadores cross-site, OIDF oficial y carga de
30 minutos de otras fases conservan su alcance histórico; no se presentan como campañas
repetidas para este cambio. No hay nueva CI remota, piloto externo ni certificación.
