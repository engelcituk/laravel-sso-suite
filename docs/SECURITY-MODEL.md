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
secuenciales SQLite se complementan con catorce carreras en MySQL/PostgreSQL con hasta
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

Recordarme guarda sólo el hash de un secreto aleatorio por dispositivo y mantiene
vencimiento absoluto, rotación y detección estricta de replay. La cookie central es
host-only/HttpOnly/SameSite=Lax y Secure con HTTPS; no contiene ID Tokens ni contraseña.
La restauración no acredita autenticación fresca ni confirmación de contraseña. El listado
y las mutaciones se aíslan por sujeto; CSRF y contraseña reciente protegen los cambios.
La revocación cierra el dispositivo completo, incluidas las sesiones anteriores a una
recuperación. PasswordReset y el fingerprint de credenciales impiden reutilizar acceso
tras cambios de contraseña. Ver política y límites en REMEMBER-DEVICES.md.

La auditoría usa un catálogo cerrado y referencias HMAC separadas por instalación y rol;
no registra tokens, códigos, credenciales, atributos personales ni requests/modelos.
Los callbacks esperan commit y desaparecen en rollback. El sink de log es opt-in, con
retención y permisos del anfitrión; no es una outbox durable de auditoría. Los diagnósticos
no exponen valores de configuración ni excepciones. Ver [garantías y operación](AUDIT-OPERATIONS.md).

## Validación externa pendiente

Los tests propios no equivalen a certificación OIDC. Faltan revisión independiente,
certificación externa de OpenID Foundation y evaluación operativa en el destino real.
Una campaña local de 30 min, tres motores de navegador y dos despliegues ya se
verificaron; [métricas, fallos y límites](DEPLOYMENT-VALIDATION.md). No acredita carga
distribuida/multijornada. La fase posterior de [compatibilidad ampliada](COMPATIBILITY.md)
pasó nueve resoluciones PHP y cuatro perfiles SQL: READ COMMITTED y REPEATABLE READ
en ambos motores. Se corrigieron snapshots antiguos y reintentos de serialización,
conservando rollback y callbacks después de commit. Los llamadores que abren transacciones
deben reintentar la transacción exterior completa; no se garantiza éxito sin límite de intentos.
