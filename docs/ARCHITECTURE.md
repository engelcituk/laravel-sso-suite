# Arquitectura

Estado inicial experimental. No certificado por OpenID Foundation ni listo para producción.

Tres paquetes Composer independientes: `laravel-sso/server`, `laravel-sso/client` y
`laravel-sso/contracts`. Namespace provisional `LaravelSso`; antes de publicar se
pueden renombrar los manifiestos y namespaces conjuntamente (no en tiempo de ejecución).
El cliente no depende de Passport ni del servidor. Redis es opcional.

## Identidad y autorización

El OP conserva credenciales y autentica mediante el guard Laravel de la aplicación anfitriona.
El RP conserva una representación Eloquent con clave local e índice único `(sso_issuer,
sso_subject)`, sin contraseña. La elección fue confirmada por el usuario: las secciones
6–7 sustituyen las menciones contradictorias de clientes sin tabla users del documento.
Los Gates, Policies y relaciones usan la clave local. Nunca se vincula por email.

## Primera vertical

1. RP crea state, nonce y verifier aleatorios, con caducidad y consumo único.
2. OP valida cliente, redirect exacto, scope openid y PKCE S256 antes de autenticar.
3. Passport/League emite y consume el código; una extensión acotada de su repositorio
   asocia nonce, auth_time, sid y selección autorizada de claims al identificador del código.
4. La respuesta Bearer de League añade un ID Token RS256 sólo al intercambio OIDC.
5. RP obtiene discovery/JWKS exclusivamente de URLs previamente confiadas, valida firma,
   kid, algoritmo, issuer, audience, fechas y nonce antes de aprovisionar.
6. SessionGuard inicia sesión local y regenera el identificador.

Los tokens OAuth no son contraseñas ni reemplazan la sesión Laravel. El OP y cada RP
tienen base de datos, cookies y APP_KEY independientes.

## Límites de extensión

Contracts comparte identidad verificada, DTO de auditoría y utilidades PHP para archivos
de instalación e informes, sin Laravel/Passport. El contrato de aprovisionamiento pertenece
al cliente. Los modelos, guard y políticas son configurables; no hay ACL ni frontend externo
obligatorio. El OP incluye vistas Blade de consentimiento/logout y dispositivos propios.
Las rutas tienen prefijos propios. Los instaladores conservan archivos y una sola migración
por paquete. Los doctores son de lectura y añaden HTTP sólo en el RP online.
Los eventos de seguridad se emiten con referencias HMAC después de commit; sink privado
opt-in y límites en [auditoría y operaciones](AUDIT-OPERATIONS.md).

La integración con los repositorios Bridge y ResponseTypeInterface de Passport/League
no está documentada como API estable por Laravel: es un riesgo de compatibilidad explícito.
Las pruebas integradas y el lockfile deben comprobarla tras cada actualización. Se compone
un AuthorizationServer aislado con los repositorios de Passport, sin sustituir los grants,
rutas ni respuesta OAuth de la aplicación anfitriona.

## Sesiones

Una sesión global opaca se guarda por separado de la sesión web central. Cada código y
token OIDC queda ligado a ella. El cliente verifica su vigencia antes de operaciones
autenticadas; la política estricta falla cerrada. Ver `SECURITY-MODEL.md` y el roadmap.

En el OP, `sso_devices` agrupa los sid de un navegador y `sso_remember_tokens` conserva
hashes e historial de rotación. Un dispositivo puede recuperar una sesión web hasta el
vencimiento absoluto elegido, conservando auth_time. Sus controles propios exigen
contraseña reciente y CSRF. Revocación y emisión se coordinan con el bloqueo del
dispositivo antes del sid; la limpieza respeta hints y entregas pendientes.
