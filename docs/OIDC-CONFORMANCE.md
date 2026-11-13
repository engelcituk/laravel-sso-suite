# Perfil OIDC Code y conformidad

Actualizado 2026-11-13. Se implementó y comprobó el perfil del proyecto para Authorization
Code, RS256 y PKCE S256. **No se afirma conformidad completa de OpenID Connect ni
certificación:** la suite oficial todavía no se ha podido ejecutar.

## Perfil implementado

| Superficie | Comportamiento |
| --- | --- |
| Authorization Endpoint | GET/POST form; response_type=code; redirect exacto registrado; S256 obligatorio |
| Transacción | nonce/state opcionales para RPs externos en Code; nuestro RP genera y exige ambos |
| Respuestas | query/form_post; state preservado y escapado; iss conforme RFC 9207; no-store |
| Errores | invalid_request, invalid_scope, unsupported_response_type, unauthorized_client, login_required, consent_required, access_denied |
| Parámetros no admitidos | request, request_uri y registration producen sus errores OIDC; nunca se descarga request_uri |
| Prompts | none/login/consent/select_account; combinaciones admitidas salvo none con otros valores |
| Reautenticación | max_age incluido 0 y prompt=login exigen nuevo Login Laravel, ligado a solicitud y TTL |
| Hints | id_token_hint verificado orienta cuenta, nunca autentica; hints/locales no conceden acceso |
| Consentimiento | POST separado con CSRF, transacción de un uso, cuenta/cliente visibles y cambio de cuenta |
| Token Endpoint | client_secret_basic, client_secret_post y cliente público; no mezcla de métodos; código ligado a RP/redirect/PKCE |
| ID Token | RS256, iss/sub/aud/iat/exp/auth_time/sid, nonce si se pidió y at_hash del access token exacto enviado |
| UserInfo | GET/POST con Bearer header; POST form también permite access_token en cuerpo, sin mezclar credenciales |
| Transporte Bearer | query access_token rechazado para evitar filtraciones; errores JSON y WWW-Authenticate |
| RP del proyecto | valida firma/alg/kid, iss/aud/azp/fechas/nonce, at_hash cuando existe y max_age configurado |
| RP-Initiated Logout | GET/POST, hint opcional, sin cookie, hint expirado reciente, client_id coherente, confirmación/cancelación CSRF |
| Back-channel RP | evento/jti/aud/iss/sub/sid y frescura; exp opcional validado si existe; sin nonce; recibos durables |

Discovery anuncia sólo lo implementado. No anuncia claims parameter, request object,
registro dinámico, implicit, hybrid, refresh, JWE ni algoritmos alternativos. Los scopes
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

## Suite oficial preparada, todavía no ejecutada

Se descargó [conformance-suite de OpenID Foundation](https://gitlab.com/openid/conformance-suite)
en `.runtime/oidf-suite`, separado del producto. Revisión inspeccionada:
`daca6ad2068468d42557ea1152f899cc3ac01a10`. El descriptor versionado
`conformance/oidf-suite.json` fija esa revisión y los cinco planes relevantes.

```sh
composer test:oidf:preflight
```

El preflight sólo lee requisitos y guarda `oidf-preflight.json` en el directorio de informes.
Salida 2 significa **bloqueado antes de ejecutar**, sin ningún test oficial aprobado/fallido.
En este equipo falta Docker. Java 8 está presente y no hay toolchain nativo Maven/JDK 21;
el builder oficial Docker ya proporciona Maven/JDK 21. El preflight no prueba daemon,
TLS, DNS, conectividad ni automatización del navegador.

## Continuar en un entorno con Docker

La [guía oficial](https://gitlab.com/openid/conformance-suite/-/wikis/Developers/Build-%26-Run)
es la referencia. En un checkout nuevo:

```sh
git clone https://gitlab.com/openid/conformance-suite.git .runtime/oidf-suite
git -C .runtime/oidf-suite checkout daca6ad2068468d42557ea1152f899cc3ac01a10
git -C .runtime/oidf-suite status --short
```

Define un caché Maven local privado y utiliza los compose inspeccionados, por ejemplo:

```powershell
$env:MAVEN_CACHE = Join-Path (Get-Location) '.runtime/maven-cache'
docker compose -f .runtime/oidf-suite/builder-compose.yml run --rm builder
docker compose -f .runtime/oidf-suite/docker-compose.yml up -d --build
```

**Estos comandos de Docker no se ejecutaron aquí.** El compose oficial utiliza
`https://localhost.emobix.co.uk:8443`, MongoDB y nginx. Comprueba resolución local y
confianza TLS según su guía. El issuer del OP debe ser HTTPS privado y accesible desde
navegador y contenedor: sus loopbacks son distintos. No abras un túnel público ni
desactives TLS para fingir una ejecución de conformidad.

Registra dos clientes confidenciales diferentes; el mismo primero puede probar Basic y
secret_post. Usa el callback exacto exportado por el plan local, normalmente bajo
`https://localhost.emobix.co.uk:8443/test/a/ALIAS/callback`, sin usar la instancia pública.
`conformance/oidf-config.example.json` contiene placeholders con campos inspeccionados
en la fuente oficial; cópialo a `.runtime` antes de añadir secretos. Sigue las instrucciones
interactivas del plan o prepara automatización de navegador en ese entorno.

Planes verificados en el código de la revisión fijada:

1. `oidcc-config-certification-test-plan`.
2. `oidcc-basic-certification-test-plan` con discovery/static_client y code.
3. `oidcc-formpost-basic-certification-test-plan`.
4. `oidcc-rp-initiated-logout-certification-test-plan`.
5. `oidcc-backchannel-rp-initiated-logout-certification-test-plan`.

Para logout registra exactamente las URLs que exporte el plan, incluye su back-channel en
la allowlist y procesa la bandeja durable. La [guía OP oficial](https://openid.net/certification/connect_op_testing/)
explica clientes, módulos y evaluación de resultados.

**Diferencia conocida:** exigimos S256 también para clientes confidenciales. El plan Basic
inspeccionado contiene módulos sin PKCE y otro módulo específico con PKCE; no se promete
aprobar todos sin ajustar el perfil de pruebas. No se desactivó PKCE para obtener un verde.
Tampoco se anuncian address/phone/offline_access ni algoritmos no implementados. Registra
cada fallo/advertencia/revisión/salto real, sin inventar exclusiones aprobadas.

Conserva revisión, configuración privada, versiones, variantes, IDs y exports originales
en `.runtime/conformance`. Actualiza el resumen redactado OIDC-CONFORMANCE-RESULTS.md sólo
tras revisar los resultados. No se creó cuenta remota, se subieron evidencias ni se solicitó
certificación o publicación.

## Fuentes normativas

- [OIDC Core](https://openid.net/specs/openid-connect-core-1_0.html),
  [Discovery](https://openid.net/specs/openid-connect-discovery-1_0.html).
- [Form Post](https://openid.net/specs/oauth-v2-form-post-response-mode-1_0.html),
  [RP-Initiated Logout](https://openid.net/specs/openid-connect-rpinitiated-1_0.html),
  [Back-Channel Logout](https://openid.net/specs/openid-connect-backchannel-1_0.html).
- [RFC 6749](https://www.rfc-editor.org/rfc/rfc6749), [RFC 6750](https://www.rfc-editor.org/rfc/rfc6750),
  [RFC 9207](https://www.rfc-editor.org/rfc/rfc9207), [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700).
