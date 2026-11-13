# Perfil OIDC Code y conformidad

Actualizado 2026-11-13. Se implementó y comprobó el perfil del proyecto para Authorization
Code, RS256 y PKCE S256. **No se afirma conformidad completa de OpenID Connect ni
certificación. La suite oficial se ejecutó localmente y sus resultados y revisión de
evidencias están en [OIDC-CONFORMANCE-RESULTS](OIDC-CONFORMANCE-RESULTS.md).

## Perfil implementado

| Superficie | Comportamiento |
| --- | --- |
| Authorization Endpoint | GET/POST form; response_type=code; redirect exacto registrado; S256 obligatorio por defecto; excepción explícita sólo para confidenciales |
| Transacción | nonce/state opcionales para RPs externos en Code; nuestro RP genera y exige ambos |
| Respuestas | query/form_post; state preservado y escapado; iss conforme RFC 9207; no-store |
| Errores | invalid_request, invalid_scope, unsupported_response_type, unauthorized_client, login_required, consent_required, access_denied |
| Parámetros no admitidos | request, request_uri y registration producen sus errores OIDC; nunca se descarga request_uri |
| Prompts | none/login/consent/select_account; combinaciones admitidas salvo none con otros valores |
| Reautenticación | max_age incluido 0 y prompt=login exigen nuevo Login Laravel, ligado a solicitud y TTL |
| Hints | id_token_hint verificado orienta cuenta, nunca autentica; hints/locales no conceden acceso |
| Consentimiento | POST separado con CSRF, transacción de un uso, cuenta/cliente visibles y cambio de cuenta |
| Claims | selección por userinfo/id_token, essential, value/values; consentimiento visible y selección persistida por código/token; sub no puede cambiar la cuenta |
| Contexto de autenticación | acr_values_supported=["0"]; ID Token declara la clase real 0; un acr esencial no disponible impide autenticar |
| Token Endpoint | client_secret_basic, client_secret_post y cliente público; no mezcla de métodos; código ligado a RP/redirect/PKCE |
| ID Token | RS256, iss/sub/aud/iat/exp/auth_time/sid, nonce si se pidió y at_hash del access token exacto enviado |
| UserInfo | GET/POST con Bearer header; POST form también permite access_token en cuerpo, sin mezclar credenciales |
| Perfil | atributos normales configurables mediante claim_attributes; se omiten valores ausentes o con tipos incorrectos; no se inventan datos ni se cargan relaciones |
| Transporte Bearer | query access_token rechazado para evitar filtraciones; errores JSON y WWW-Authenticate |
| RP del proyecto | valida firma/alg/kid, iss/aud/azp/fechas/nonce, at_hash cuando existe y max_age configurado |
| RP-Initiated Logout | GET/POST, hint opcional, sin cookie, hint expirado reciente, client_id coherente, confirmación/cancelación CSRF |
| Back-channel RP | evento/jti/aud/iss/sub/sid y frescura; exp opcional validado si existe; sin nonce; recibos durables |

Discovery anuncia claims parameter, atributos normales configurados y acr=0. No anuncia
request object, registro dinámico, implicit, hybrid, refresh, JWE ni algoritmos alternativos. Los scopes
son openid/profile/email. Los hints de UI no implementados se ignoran cuando corresponde;
no autentican ni vinculan por correo. El RP propio usa respuesta query y exige sid/auth_time
para comprobar sesiones; no es un RP genérico para cualquier OP sin esas extensiones.
Un RP externo que pida form_post debe preparar su callback POST y sus cookies.

La reautenticación reemplaza el sid anterior y el evento Logout revoca sus sesiones
vinculadas. El login anfitrión debe emitir Login de SessionGuard. Un marcador de sesión
o un guard con usuario no bastan para superar prompt=login/max_age.

## Pruebas propias reproducibles

```sh
composer test:conformance
```

Genera `.runtime/conformance/junit.xml` y `result.json`, con estado, PHP, recuentos y
`official_openid_foundation_suite=false`. Son archivos privados ignorados por Git; no se
suben a ningún servicio. Las pruebas son originales y derivan de reglas normativas;
no son copias de módulos de OpenID Foundation.

| Norma | Pruebas en tests/Conformance |
| --- | --- |
| Core 3.1.2.1 | nonce/state opcionales, POST, hints/locales, prompts combinados y max_age 0/60 |
| Core 3.1.2.3–6 | none sin UI, consent_required, access_denied, errores con state/redirect exactos |
| Core 3.1.3.2 / RFC 6749 | código ligado a RP/redirect, credenciales ausentes/ambiguas y parámetros duplicados |
| Core 9.1 | Basic form-encoded, incluidos espacios, dos puntos y signo + |
| Core 3.1.3.7–8 | at_hash exacto, tipos aud/azp, auth_time/max_age y confusión entre JWT |
| Core 5.3 / RFC 6750 | UserInfo GET, POST header/body, scopes, rechazo de query/credenciales múltiples |
| Discovery 3 / RFC 9207 | metadatos y rechazo RP de issuer ausente/incorrecto antes del token exchange |
| Form Post 2 | action/method, campos escapados, auto-submit con CSP y no-store |
| RP-Initiated Logout 2–4 | sin cookie/hint, expiración/firma, client_id, redirect, cancelación y cambio de cuenta |
| Back-Channel 2.4/2.6 | exp/sid opcionales, tipos, evento y frescura aun sin exp |

La suite completa añade firma/alg/kid, replay, PKCE, rotación, aprovisionamiento y revocación.
E2E usa tres servidores reales y comprueba cookies/bases/claves separadas, ACL y CSRF positivo
y negativo. No equivale a probar todos los navegadores ni el harness oficial.

## Suite oficial ejecutada localmente

La revisión oficial `daca6ad2068468d42557ea1152f899cc3ac01a10` permanece sin cambios en
`.runtime/oidf-suite`. Los cinco planes, el OP Laravel y su worker se ejecutan en la red
Docker, con almacenamiento Linux para evitar el salto a PHP de Windows. Las pruebas de
paquetes y los ejemplos siguen funcionando con Herd/Laragon. Los resultados,
IDs y límites reales están en [OIDC-CONFORMANCE-RESULTS](OIDC-CONFORMANCE-RESULTS.md).

```powershell
.\tools\oidf-docker.ps1 -Action Status
composer test:oidf:preflight
.\tools\oidf-docker.ps1 -Action Start
.\tools\oidf-docker.ps1 -Action Run -Mode compatibility -Label oidc
.\tools\oidf-docker.ps1 -Action Stop
```

Usa PHP >=8.3 con OpenSSL, curl y SQLite. El helper acepta `-PhpPath` y en este equipo
selecciona PHP 8.3 de Herd. La [guía de Windows](OIDF-WINDOWS-SETUP.md) explica TLS,
puertos, almacenamiento privado y logs. `Start` prepara el OP aislado con política estricta;
`Run -Mode compatibility` habilita la excepción sólo en sus dos clientes confidenciales de laboratorio.
Para evaluar la política predeterminada usa `Run -Mode strict`. El coordinador y el worker
deben usar la misma base del OP: se ejecutan mediante el helper dentro de Docker.

El helper verifica el checkout, compila la misma revisión en un volumen Linux para evitar
los recorridos lentos sobre NTFS y copia el JAR al directorio ignorado. La API y Mongo deben
estar listos antes de crear planes. El preflight comprueba requisitos; no ejecuta módulos.

El runner llama a la API de los planes/módulos oficiales, sin modificar sus condiciones ni
fabricar aprobados. Selecciona discovery/static_client donde lo permite el plan; Code,
query/form_post y Basic/secret_post proceden de las variantes fijadas por cada plan.
Registra dos clientes distintos y el campo oficial `client_secret_post`, reutilizando el
primero porque nuestro OP admite ambos métodos. Cada módulo recibe navegador independiente;
se limpia sólo el rate limit y la bandeja del fixture. Back-channel se registra únicamente
en su plan, con allowlist exacta y un worker real en proceso separado.

El navegador automático es HtmlUnit, incluido en la suite oficial. Sus reglas rellenan el
login central, confirman decisiones y conservan instantáneas DOM mediante la función oficial
`update-image-placeholder`. No son capturas de Chrome ni una evaluación visual de todos los
navegadores. Los estados REVIEW/WARNING/SKIPPED se conservan y se explican; no se convierten
en PASSED. El runner devuelve 1 si existen fallos, advertencias o revisiones.
`tools/oidf-review.php` audita sus exports sin cambiar ese estado: comprueba semántica de
formularios, CSRF, origen de acciones, respuestas 400 y capacidades para los saltos.

**Política PKCE:** S256 es obligatorio por defecto y siempre para clientes públicos. El
usuario autorizó una excepción explícita por cliente confidencial para evaluar Basic,
cuyos flujos anteriores no envían PKCE. Si PKCE está presente se valida S256 y su verifier;
nuestro cliente Laravel siempre lo envía. No se modificó la suite para eludir comprobaciones.
No se anuncian address/phone/offline_access ni claims/request objects no implementados.

Los informes originales, configuración con secretos y ZIP exports permanecen en
`.runtime/conformance/official-*/`. No deben subirse a Git: contienen tokens, credenciales,
cookies y datos del navegador de laboratorio. Se documenta sólo el resumen sin secretos.
No se creó cuenta remota ni se solicitó certificación o publicación.

## Fuentes normativas

- [OIDC Core](https://openid.net/specs/openid-connect-core-1_0.html),
  [Discovery](https://openid.net/specs/openid-connect-discovery-1_0.html).
- [Form Post](https://openid.net/specs/oauth-v2-form-post-response-mode-1_0.html),
  [RP-Initiated Logout](https://openid.net/specs/openid-connect-rpinitiated-1_0.html),
  [Back-Channel Logout](https://openid.net/specs/openid-connect-backchannel-1_0.html).
- [RFC 6749](https://www.rfc-editor.org/rfc/rfc6749), [RFC 6750](https://www.rfc-editor.org/rfc/rfc6750),
  [RFC 9207](https://www.rfc-editor.org/rfc/rfc9207), [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700).
