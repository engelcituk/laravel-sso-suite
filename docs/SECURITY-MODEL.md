# Modelo de seguridad

## Fronteras de confianza

En modo SSO sólo el OP recibe la contraseña central. En modo local, el anfitrión usa
su Auth normal; el paquete no recibe ni sincroniza esas credenciales. El RP confía en
un issuer configurado y sus claves públicas.
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
tener el mismo sub que el ID Token validado antes de resolver o aprovisionar al usuario local.
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

## Auth local y cambio de modo

El flag se configura por el operador. No depende de disponibilidad del OP: una petición
SSO falla cerrada aunque exista una contraseña local. La integración automática protege
rutas convencionales de Auth en `web` para el guard seleccionado. Rutas personalizadas,
otros guards/middleware o integración manual requieren `sso.local` y `auth` + `sso.session`.
Rutas fuera de esa integración conservan su comportamiento y requieren revisión propia.
Las sesiones locales anteriores no acreditan identidad central y las sesiones SSO se
invalidan al volver a local; se exige nueva autenticación. El cierre SSO conserva hash y
remember_token nativos. El registro/recuperación de contraseña local queda bajo política
del anfitrión y no debe exponerse sin el middleware al usar SSO.

La vinculación manual exige un operador autorizado y un iss/sub verificado. Un mismo
email no demuestra identidad; una vinculación existente no cambia de propietario.
En greenfield la fábrica incluida crea una cuenta nueva sólo después de validar OIDC:
nombre/email/verificación y un hash de un valor aleatorio descartado para el NOT NULL
nativo. Nunca copia contraseñas o privilegios centrales. Requiere email único y rechaza
colisiones, incluidas diferencias de mayúsculas, sin vincular ni modificar al dueño.
Alta y vínculo usan una transacción; denegar la política revierte ambos. Los siguientes
logins sólo leen el propietario establecido. [Contrato y evidencia](GREENFIELD-PROVISIONING.md).
La fábrica personalizada de `automatic` es código confiable del anfitrión: elige atributos permitidos y
campos obligatorios, nunca recibe la contraseña central. El paquete no escribe atributos
del usuario existente. Una cuenta nueva necesita un proceso local de establecimiento
de contraseña si luego se desea usar Auth local. [Pruebas y límites](OPTIONAL-AUTH.md).

## Cuentas y administración central

Las aplicaciones nuevas registradas por CLI exigen asignación explícita de usuarios
centrales. Revocar una asignación invalida sólo sus tokens de esa aplicación; no borra
cuentas ni modifica el acceso a otros sistemas. El bloqueo de la inscripción serializa
emisión y retirada, con escritura de la política existente para que PostgreSQL RR
reintente un snapshot anterior al token. El registro público opt-in no concede asignaciones.
La recuperación usa tokens con hash, expiración, throttle y locks para consumo de un uso;
su URL se deriva del issuer configurado. Cambia sólo la contraseña/recaller del objetivo
y revoca sus dispositivos/sesiones/tokens. No autentica automáticamente ni altera
contraseñas cliente. Los endpoints alternativos se cierran al delegar en el login del
anfitrión. [Contrato, correo y límites](CENTRAL-ACCOUNTS.md).

La integración agnóstica incorpora un backend de contraseña central opcional con CSRF,
sesión regenerada y limitación de intentos. Valida sin rehash ni escritura en users.
El modo `auto` conserva el login/MFA del anfitrión y cierra el POST alternativo del paquete;
habilitarlo con `authentication=package` es una decisión explícita. No incorpora MFA.
`active_column` es opcional; si se configura debe existir y ser verdadera. Las APIs JSON
de login/consentimiento/logout usan la sesión del mismo origen y no exponen secretos
de cliente. No equivalen a un flujo Bearer stateless para una SPA remota.
[Contratos y límites](STACK-AGNOSTIC.md), [pruebas](AGNOSTIC-VALIDATION.md).

Los servicios públicos de clientes requieren autorización del anfitrión, también para
consultas. No crean un panel ni un rol administrativo. Sus DTO de lectura excluyen
secretos; editar invalida los códigos/tokens de esa aplicación y revocar la bloquea
definitivamente. El bloqueo por cliente coordina estas operaciones con la emisión OIDC.
No se modifican cuentas ni se cierran sesiones de otras aplicaciones. La revocación
no elimina una cookie o un JWT ya recibido: su efecto en el RP depende de las
comprobaciones de sesión configuradas. [API y garantías](CLIENT-REGISTRATION-SERVICE.md).

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
