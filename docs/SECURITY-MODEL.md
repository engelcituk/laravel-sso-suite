# Modelo de seguridad

## Fronteras de confianza

Sólo el OP recibe contraseñas. El RP confía en un issuer configurado y sus claves públicas.
No se confía en claims decodificados antes de validar la firma. Las identidades locales
no conceden roles. Las cookies y bases de datos no se comparten entre dominios.

## Controles y amenazas

| Amenaza | Control requerido | Evidencia |
| --- | --- | --- |
| Login CSRF y replay | state/nonce únicos con TTL, callback consume transacción | ClientTest y E2E CSRF |
| Intercepción/inyección de código | PKCE S256, client y redirect ligados al código | ProtocolTest con Passport real |
| JWT adulterado/confusión de algoritmos | sólo RS256, kid conocido, iss/aud/azp/tiempos/nonce | ClientTest negativos |
| Vinculación errónea de cuentas | UNIQUE issuer/subject sensible a mayúsculas, jamás email | ClientTest y concurrencia MySQL/PostgreSQL |
| Session fixation | SessionGuard login + regenerate | ClientTest callback |
| SSRF | allowlist de endpoints exactos, HTTPS, redirects desactivados | ClientTest transporte |
| Mix-up de proveedor | iss en respuesta de autorización y validación antes de enviar código/secreto | Conformance/RelyingPartyValidationTest |
| Ambigüedad de credenciales | Basic form-encoded, métodos únicos y parámetros query/form no repetidos | Conformance/OidcCodeProfileTest |
| Sesión central revocada | índice local y comprobación central acotada | SessionTest, LogoutTest y E2E |
| Fuga de tokens | no logs de cuerpos/headers, no localStorage, no tokens en contraseñas | revisión pendiente |

Producción requiere HTTPS, cookies Secure/HttpOnly/SameSite=Lax, APP_KEY distintas,
secretos fuera del repositorio, rutas de login limitadas y credenciales mediante Laravel Hash.
HTTP sólo se habilita explícitamente para ejemplos locales y se prohíbe en producción.
El hostname y DNS de cada endpoint permitido son responsabilidad del operador: la allowlist
no autoriza servicios externos arbitrarios ni elimina riesgos de DNS comprometido.

## Revocación

No se promete invalidación instantánea. El presupuesto de caché y timeout, las carreras de
peticiones ya iniciadas y el fallo cerrado se documentan en SESSIONS.md. Se probaron logout
entre dos RP, suspensión, caída del OP simulada y reintentos HTTP 503.
Back-channel valida firma, evento, audiencia, sid/sub, TTL y jti; usa recibos atómicos,
índice persistente y bandeja durable. Reconoce duplicados sin reaplicarlos. Los ensayos
secuenciales SQLite se complementan con diez carreras en MySQL/PostgreSQL con hasta
ocho procesos, reservas protegidas y limpieza probada. No equivalen a campañas de carga
prolongada ni a certificación externa.
La ampliación del perfil y las pruebas normativas se documentan en OIDC-CONFORMANCE.md.
El harness oficial se ejecutó localmente en Docker tras el reinicio de Windows. Sus
resultados, advertencias y revisiones se conservan separados del grupo PHPUnit propio.
Ver OIDC-CONFORMANCE-RESULTS.md. S256 sigue siendo obligatorio por defecto; la excepción
explícita por cliente confidencial se usa sólo donde el operador la haya autorizado.
Los clientes públicos y el cliente Laravel mantienen PKCE obligatorio.
Los atributos concretos solicitados mediante claims se muestran en el consentimiento y
se conservan por código/token. sub no puede sustituir al usuario autenticado. El contexto
acr=0 no afirma MFA ni identidad acreditada; un acr esencial distinto se rechaza.

El replay revoca sólo tokens ligados al código y al cliente autenticado. UserInfo debe
tener el mismo sub que el ID Token validado antes de crear o actualizar al usuario local.
Los Request Objects siguen rechazados: se puede leer de forma acotada sólo response_mode
para entregar ese error; nunca se aceptan su redirect, identidad, state, scopes ni PKCE.

## Validación externa pendiente

Los tests propios no equivalen a certificación OIDC. Faltan revisión independiente,
certificación externa de OpenID Foundation y evaluación operativa/carga prolongada.
Los motores, aislamientos y límites ya ensayados están en DATABASE-CONCURRENCY.md.
