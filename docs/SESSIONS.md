# Sesiones, revocación y logout

Hay cuatro conceptos separados: sesión web Laravel central, fila sso_sessions global,
sesión Laravel del RP y tokens OAuth/OIDC de corta duración. Los permisos viven en el RP.
No se comparten cookies, APP_KEY ni bases de datos.

## Políticas actuales

El OP genera sid opaco aleatorio. Comprueba existencia de cuenta y, si se configuró,
su columna booleana activa; también revocación, inactividad
(1800 segundos por defecto) y duración absoluta (28800 segundos). Una sesión expirada o
revocada no se recrea silenciosamente desde la cookie central existente: requiere login.
Cada código guarda nonce/sid; cada access token tiene una vinculación persistente al sid.

El RP registra un hash del ID de su sesión local, issuer, subject y sid. El índice se consulta
en cada operación protegida. Si está revocado se cierra la sesión Laravel sin borrar users.
La verificación central usa un access token Bearer ligado a ese sid, contra `/oidc/session`.
Es una extensión privada del proyecto, no introspección OAuth ni un estándar OIDC anunciado.

Con middleware `sso.session`, una comprobación positiva se acepta durante el intervalo
configurado (30 s por defecto). Después exige otra respuesta positiva; cualquier fallo
de red, HTTP o de claims deniega acceso. **No hay modo fail-open**. `strict` consulta
en cada request. El ejemplo y la integración automática de Auth usan strict y un timeout de 3 s.

Límite operativo: nuevas verificaciones de autorización pueden aceptar una revocación durante
el intervalo de caché, más una comprobación remota ya en curso (timeout). Con defaults,
presupuesto conservador 33 s; strict reduce el intervalo a 0, conservando la carrera de una
petición ya en curso. No se garantiza un deadline de tiempo real bajo pausas del proceso ni
se cancelan operaciones que ya habían sido autorizadas. Cada aplicación debe elegir un
intervalo acorde con sus operaciones. Back-channel adelanta la invalidación sin sustituir
esa revisión periódica.

Los access tokens caducan en 300 s por defecto. Al caducar, el RP cierra su sesión local y
una nueva redirección recupera el acceso sin contraseña si la sesión global sigue vigente.
No se utilizan refresh tokens ni ID Tokens como sesiones permanentes.
El índice local también tiene `expires_at`: se toma el menor plazo entre `local_session_ttl`
y el `expires_in` recibido. El middleware comprueba esa fecha antes de aceptar una caché positiva.

## Logout

POST `/sso/logout` del RP exige CSRF, revoca su índice y cierra la sesión local. Redirige
al endpoint del OP con id_token_hint. GET/POST `/oidc/logout` aceptan solicitudes externas
y muestran confirmación; **no ejecutan el cierre por sí solos**. POST `/oidc/logout/confirm`
exige CSRF/transacción de un uso y revoca el sid/access tokens. También permite cancelar.
El hint es opcional. Sin hint se confirma la sesión central actual; con hint firmado reciente
puede funcionar sin cookie central. Si hay otra cuenta conectada, se pide confirmar el
cierre de la cuenta actual y no se revoca otra identidad silenciosamente. La confirmación
falla si cambia la sesión central mientras se decide. Un hint nunca inicia sesión.
Sólo se redirige a post_logout_redirect_uri registrado exactamente, asociado a client_id
válido o a la audiencia del hint. Los hints expirados mantienen firma y vínculo a una
sesión RP reciente (logout_hint_retention, 86400 s después de su expiración absoluta).
Si el OP está caído al iniciar logout, la sesión local se cierra pero el
cierre global no se confirma; el operador/usuario debe reintentarlo en el OP.

El evento Laravel Logout del guard central también revoca su sid. Administración puede
inyectar `GlobalSessions` y llamar `revoke($sid)` para un dispositivo/sesión o
`revokeAll($subject)` para todos. `revoke($sid)` también retira el acceso persistente y
los otros sid del mismo dispositivo, para que Recordarme no reabra el logout. Esas APIs deben exponerse sólo tras autorización y CSRF
en tu aplicación, nunca como endpoints públicos por sujeto. Marcar como falsa la columna
existente seleccionada mediante `active_column` bloquea
las verificaciones; para invalidar definitivamente las sesiones al suspender una cuenta,
llama también revokeAll en tu servicio administrativo.

Revocación y bandeja sso_logout_deliveries se guardan en una transacción. El comando
`sso:deliver-logouts` firma tokens con jti estable por entrega, sid, sub, issuer, audience,
iat, exp y evento backchannel-logout. Reintenta fallos con espera creciente, hasta una hora
entre intentos, sin límite de intentos. Una reserva de al menos 60 s (o timeout HTTP + 5 s,
si es mayor) evita tomar la misma fila simultáneamente. MySQL/PostgreSQL usan SKIP LOCKED;
si un proceso muere se recupera después de la reserva. La generación de attempts impide
que un resultado tardío sobrescriba al nuevo dueño. La entrega sigue siendo al menos una vez.

El RP valida los tokens sin nonce, registra un recibo atómico e invalida las filas vinculadas.
Acepta exp omitido conforme al estándar y mantiene el límite max_token_age sobre iat;
cuando exp está presente valida su tipo y vigencia. Sid es opcional si hay sub.
Un duplicado firmado válido recibe 200 y no se reaplica. El navegador desconectado puede
conservar su cookie, pero el índice revocado impide reutilizarla. Monitoriza intentos pendientes,
alertas y tamaño de las tablas. `sso:prune-server` y `sso:prune-client` aplican retención
conservadora mediante scheduler del anfitrión. La matriz prueba hasta ocho procesos
concurrentes en MySQL/PostgreSQL; ver [resultados y plazos](DATABASE-CONCURRENCY.md).

## Recordarme y administración de dispositivos

El ejemplo central ofrece Recordarme opt-in y `/sso/devices`. La credencial persistente
se rota en cada recuperación, conserva la fecha de autenticación original y no extiende
su vencimiento absoluto. Replay verificado revoca el dispositivo; una contraseña reciente
y CSRF protegen la administración. El flujo SSO no crea un recaller local; el Auth nativo
puede conservar el suyo cuando el cliente funciona en [modo local](OPTIONAL-AUTH.md).
Ver [política, integración, limpieza y resultados reales](REMEMBER-DEVICES.md).

Las carreras de revocación, entrega y recuperación en MySQL/PostgreSQL y una
[campaña local de 30 min](DEPLOYMENT-VALIDATION.md) ya se ejecutaron: caída HTTP del OP,
pausa de entrega, rechazo de acceso y drenaje comprobados. Siguen fuera del cierre
certificación externa, pérdida TCP silenciosa y evaluación distribuida/multijornada.
