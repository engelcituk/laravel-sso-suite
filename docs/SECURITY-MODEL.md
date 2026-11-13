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
| Vinculación errónea de cuentas | UNIQUE issuer/subject, jamás email | ClientTest; concurrencia pendiente |
| Session fixation | SessionGuard login + regenerate | ClientTest callback |
| SSRF | allowlist de endpoints exactos, HTTPS, redirects desactivados | ClientTest transporte |
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
secuenciales SQLite no prueban concurrencia distribuida ni conformidad completa.

## Validación externa pendiente

Los tests propios no equivalen a certificación OIDC. Faltan revisión independiente,
OpenID Foundation conformance, pruebas MySQL/PostgreSQL y evaluación operativa.
