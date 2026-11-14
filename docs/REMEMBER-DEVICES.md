# Recordarme central y dispositivos

Implementado y probado localmente el 2026-11-14. El OP ofrece una credencial persistente
por navegador y una pantalla de administración en `/sso/devices`. La portada del ejemplo
central enlaza a ella. Los RP conservan SessionGuard, usuarios Eloquent y sesiones locales;
no reciben contraseñas ni credenciales Recordarme propias.

## Uso e integración

El formulario central añade «Recordarme en este dispositivo», desmarcado inicialmente.
El login sigue verificando credenciales mediante el proveedor Eloquent de Laravel y
SessionGuard. El evento `Validated` identifica una comprobación real de contraseña;
`Login` registra el dispositivo y conserva la integración con Laravel Auth.

El ejemplo utiliza `Auth::attempt($credentials, false)` y valida `remember` como booleano;
el listener toma esa elección sólo después de validar las credenciales. Una llamada
explícita a `Auth::attempt($credentials, true)` también se convierte a la política del
paquete. La cookie recaller nativa se retira: no se utiliza su token global compartido
para reanudar dispositivos. Los recallers nativos antiguos no pueden eludir esta política.
Los logins manuales con `SessionGuard::login` siguen disponibles para la aplicación anfitriona;
no equivalen por sí solos a una confirmación reciente de contraseña para administrar dispositivos.

El provider incorpora `CentralDeviceSession` al grupo web del kernel, después de iniciar
la sesión y antes de comprobar autenticación. Una cookie de sesión web perdida puede
recuperarse mediante Recordarme en una solicitud GET. No se reanuda durante una
transacción que exige reautenticación ni se sustituye otra identidad ya autenticada.
Los endpoints de token/API no autentican mediante esta cookie.

## Credencial, rotación y límites

La cookie contiene un selector y un verificador aleatorios de 256 bits cada uno; Laravel
la cifra mediante el middleware web habitual. La base guarda únicamente SHA256 del
verificador, el dispositivo, sus fechas y el historial consumido. No se guardan tokens
persistentes en texto claro, ID Tokens ni hashes de contraseña copiados en esa cookie.

- Cookie host-only, Path=/, HttpOnly, SameSite=Lax. Con HTTPS obligatorio utiliza Secure
  y el prefijo `__Host-`, incluso si la aplicación configuró un dominio compartido para
  otras cookies. HTTP sólo se permite por la opción de desarrollo existente.
- Duración absoluta de 30 días por defecto. La rotación no amplía ese vencimiento.
- Cada recuperación consume una credencial, crea otra y regenera la sesión Laravel.
  Se mantiene el `auth_time` original y se retira la confirmación de contraseña/época de
  login fresco. Por tanto, no satisface `prompt=login` ni un `max_age` que exija credenciales nuevas.
- Una reutilización con verificador correcto revoca la familia completa del dispositivo,
  incluidas sus sesiones y tokens. Un selector/verificador adivinado se rechaza sin
  revocar a otra persona.
- Política estricta sin ventana de reutilización: dos reanudaciones simultáneas de la
  misma cookie se consideran replay y requieren un nuevo login. Dispositivos con
  credenciales distintas pueden reanudarse simultáneamente sin afectarse.
- La cuenta debe seguir activa. Un fingerprint HMAC ligado a la clave de la aplicación y
  al hash de contraseña invalida el acceso tras cambios de contraseña; el evento Laravel
  `PasswordReset` revoca además todos los dispositivos del sujeto. No se almacena el hash
  de contraseña original en las tablas de dispositivos.

La cookie ya no sirve después del logout, revocación, vencimiento o invalidación de
credenciales. Las cookies de sesión nativas anteriores a esta funcionalidad también se
contrastan con el fingerprint de contraseña que SessionGuard guardó antes de asociarlas.
Al suspender una cuenta, llama igualmente a `GlobalSessions::revokeAll($subject)` para
invalidar definitivamente los dispositivos existentes, además de bloquear `is_active`.

## Administración del propietario

`/sso/devices` muestra sólo los dispositivos del sujeto central autenticado, con nombre,
actividad reciente, estado y vencimiento de Recordarme. El User-Agent es orientativo,
no una prueba de identidad física. La IP no se almacena salvo habilitación explícita.
Los identificadores de sujeto se comparan con sensibilidad a mayúsculas en MySQL.
HTML y JSON omiten hashes de credenciales y verificadores persistentes.

El propietario puede cambiar un nombre, cerrar un dispositivo concreto, cerrar los
otros conservando el actual o cerrar todos, incluido el navegador actual. Cada mutación
exige CSRF y contraseña confirmada recientemente, ligada al mismo sujeto. La confirmación
se limita a cinco intentos/minuto y no se renueva desde Recordarme. Cuando vence, el HTML
regresa al formulario de confirmación y JSON devuelve 403 sin ejecutar la operación.
Nombres y User-Agent se escapan en las vistas.

| Método / ruta predeterminada | Acción |
| --- | --- |
| GET `/sso/devices` | lista HTML, o JSON con Accept: application/json |
| POST `/sso/devices/confirm` | valida `password` y abre el plazo de confirmación |
| POST `/sso/devices/{id}/label` | cambia `label`, hasta 100 caracteres |
| POST `/sso/devices/{id}/revoke` | revoca un dispositivo propio |
| POST `/sso/devices/revoke-others` | conserva sólo el dispositivo actual |
| POST `/sso/devices/revoke-all` | revoca todos y cierra la sesión actual |

No son endpoints administrativos públicos para elegir un sujeto. Las APIs internas
`DeviceSessions::revoke` y `revokeAll` requieren autorización del anfitrión cuando se
usan fuera de estos controladores. Los cambios de dispositivos no conceden roles.

## Sesiones y limpieza

Un dispositivo puede tener varios sid tras recuperar sesiones. Revocar un sid mediante
la API pública `GlobalSessions::revoke` revoca su dispositivo completo, evitando que una
cookie Recordarme reabra el acceso después de un logout RP. Los sid anteriores conservan
su binding para los hints y las notificaciones pendientes. La emisión, restauración y
revocación respetan el orden de bloqueo dispositivo → sesión; las lecturas actuales
evitan perder sesiones confirmadas mientras una transacción MySQL mantenía un snapshot viejo.

`sso:prune-server` limpia también credenciales persistentes vencidas y dispositivos
caducados/revocados fuera de retención. Conserva el historial consumido de familias aún
vigentes y no borra dispositivos mientras sus sesiones sigan retenidas. Los nuevos
contadores son `remember_tokens` y `devices`. Los RP mantienen `sso:prune-client`.

El esquema sigue siendo **un archivo inicial por paquete**. El servidor contiene siete
tablas: las cinco anteriores, `sso_devices` y `sso_remember_tokens`; `sso_sessions` incluye
`device_id` nullable para conservar las filas previas. El cliente no añade tablas Recordarme.
Actualizar el archivo inicial no migra automáticamente una base ya instalada. En este
checkout se sincronizaron los fixtures SQLite desde esa definición canónica, con backups
privados y sin borrar identidades; no se añadieron migraciones incrementales.

## Configuración

En `sso-server.php`: `remember_enabled=true`, `remember_ttl=2592000` segundos,
`remember_cookie=sso_remember` (prefijo HTTPS automático), `devices_path=sso/devices`,
`device_password_timeout=300` segundos y `device_record_ip=false`.
La desactivación de Recordarme bloquea recuperación/emisión; usa revocación explícita
si necesitas invalidar permanentemente familias existentes. Configura correctamente el
proxy HTTPS y conserva cifrado, CSRF y almacenamiento de sesión del grupo web.

## Resultados reales

Esta tabla conserva el cierre original de Recordarme/dispositivos. La regresión posterior
de auditoría/operaciones pasó **209 / 982** en cada PHP, **224 / 1055** en MySQL y
**224 / 1053** en PostgreSQL; ver [resultados actuales](TESTING.md).

| Evaluación | Resultado |
| --- | --- |
| PHP 8.3.36 / 8.4.27 / 8.5.12, SQLite | 184 pruebas / 810 aserciones en cada versión; aprobado |
| MySQL 8.4.13, REPEATABLE READ | 199 pruebas / 883 aserciones; sin fallos ni saltos |
| PostgreSQL 18.7, READ COMMITTED | 199 pruebas / 881 aserciones; sin fallos ni saltos |
| OIDF, cinco planes sin modificar | 58 PASSED, 0 WARNING/FAILED, 16 REVIEW verificados y 10 SKIPPED justificados |
| E2E, tres aplicaciones y dos navegadores simulados con cookie jars reales | recuperación/rotación, confirmación, CSRF, revocación aislada, logout y tres entregas back-channel aprobados |

La matriz incluye cuatro carreras nuevas de Recordarme, además de las diez existentes:
ocho usos de una credencial, revocación antes de rotar, revocación desde un snapshot
anterior a la recuperación y ocho dispositivos independientes. Las conexiones y
bloqueos son reales; las cuatro carreras específicas suman 17 aserciones por motor.
La ejecución original es `remember-final-20261114-210314`; el
[informe con huellas y versiones](../conformance/database-results.json) corresponde ahora
a la regresión completa `audit-final-20261114-215054`. Los originales previos se conservan.
Las catorce carreras pasaron además tres rondas por motor, cada una con 14 pruebas y
68 aserciones, bajo las etiquetas `remember-stable-1`, `remember-stable-2` y `remember-stable-3`.
Los logs PHP, E2E y análisis están en `.runtime/remember-*`; las evidencias oficiales
están en `.runtime/conformance/official-remembered-devices-20261114-210428/`.

Se revisaron estados HTML sintéticos en un navegador real a 1280 y 390 px, sin overflow
horizontal; se verificó el nombre accesible del botón corregido con HTML renderizado.
Esto no sustituye pruebas de teclado completas, de todos los estados ni de todos los
navegadores. Capturas y veredicto se conservan localmente sin datos de cuentas reales.

La integración se contrastó con el SessionGuard instalado y la documentación primaria de
[Laravel Auth](https://laravel.com/framework/docs/13.x/authentication) y
[OWASP sobre sesiones](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html).
No se publicó ni se solicitó certificación externa.
