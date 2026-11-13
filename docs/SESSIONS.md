# Sesiones, revocación y logout

Hay cuatro conceptos separados: sesión web Laravel central, fila sso_sessions global,
sesión Laravel del RP y tokens OAuth/OIDC de corta duración. Los permisos viven en el RP.
No se comparten cookies, APP_KEY ni bases de datos.

## Políticas actuales

El OP genera sid opaco aleatorio. Comprueba cuenta activa, revocación, inactividad
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
en cada request. El ejemplo usa strict y un timeout de 3 s.

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
`revokeAll($subject)` para todos. Esas APIs deben exponerse sólo tras autorización y CSRF
en tu aplicación, nunca como endpoints públicos por sujeto. Marcar is_active=false bloquea
las verificaciones; para invalidar definitivamente las sesiones al suspender una cuenta,
llama también revokeAll en tu servicio administrativo.

Revocación y bandeja sso_logout_deliveries se guardan en una transacción. El comando
`sso:deliver-logouts` firma tokens con jti estable por entrega, sid, sub, issuer, audience,
iat, exp y evento backchannel-logout. Reintenta fallos con espera creciente, hasta una hora
entre intentos, sin límite de intentos. Una reserva de 60 s evita tomar la misma fila
simultáneamente; si un proceso muere se recupera después de la reserva.

El RP valida los tokens sin nonce, registra un recibo atómico e invalida las filas vinculadas.
Acepta exp omitido conforme al estándar y mantiene el límite max_token_age sobre iat;
cuando exp está presente valida su tipo y vigencia. Sid es opcional si hay sub.
Un duplicado firmado válido recibe 200 y no se reaplica. El navegador desconectado puede
conservar su cookie, pero el índice revocado impide reutilizarla. Monitoriza intentos pendientes,
alertas y tamaño de las tablas. La retención/limpieza automática y los workers distribuidos
bajo carga todavía necesitan pruebas adicionales.

## Pendiente

Recordarme central persistente con rotación, replay y política por dispositivo; UI de
administración de dispositivos; conformidad RP-Initiated/Back-Channel completa; carreras de
revocación y entrega en MySQL/PostgreSQL. El ejemplo no ofrece Recordarme hasta validar
esa política: no se simula guardando un ID Token ni un remember token en los clientes.
