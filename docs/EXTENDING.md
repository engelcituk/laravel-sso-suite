# Extensión

La suite agrega capacidades a tu aplicación Laravel: tu anfitrión conserva modelos,
Auth, rutas administrativas, autorización y frontend. Los servicios contienen la
lógica reutilizable; HTTP y Artisan son adaptadores. No se instala un CRUD o panel.

Para crear sistemas desde tu CRUD, inyecta
`LaravelSso\Server\Contracts\RegistersClients` y pasa un `ClientDefinition`.
El contenedor usa `Clients`; `sso:client` delega en ese mismo contrato. Devuelve un
`CreatedClient` con ID y acceso explícito al secreto inicial. No edita `.env` ni
habilita SSO. [API, validaciones y controlador completo](CLIENT-REGISTRATION-SERVICE.md).

Para el resto de tu CRUD inyecta `LaravelSso\Server\Contracts\ManagesClients`:
`listing(status: 'active', perPage: 20, page: 1)`, `find($id)`,
`update($id, new ClientChanges(name: 'Nuevo nombre'))` y `revoke($id)`.
Devuelve `ClientDetails` sin secretos; tu aplicación autoriza cada operación.
Una edición efectiva invalida los códigos y tokens del sistema; revocar es definitivo
e idempotente. Conserva usuarios, contraseñas, asignaciones y las otras aplicaciones.

`LaravelSso\Contracts\Identity` es el único objeto compartido. Contiene issuer, subject,
sessionId, authenticatedAt y atributos de identidad verificados; no roles ni contraseñas.

Para personalizar aprovisionamiento implementa
`LaravelSso\Client\Contracts\ProvisionsUsers::provision(Identity): Authenticatable` y
vincula el contrato en el service provider de tu aplicación. El callback establece la
vinculación mediante `IdentityLinks` antes de iniciar sesión; no permite reasignar una
identidad a otro usuario. Conserva política local y usuario en la misma conexión.
El aprovisionador predeterminado emite `IdentityProvisioned` tras aprobación y commit,
sin tokens; las integraciones personalizadas deben definir sus eventos equivalentes.
Si el llamador abre una transacción en MySQL/PostgreSQL, debe reintentar la transacción
completa ante deadlock/serialización, por ejemplo `DB::transaction(fn () => $users->provision($identity), 5)`.
Los callbacks del evento se descartan al hacer rollback; no se publica el intento abortado.

El proveedor Eloquent utiliza tu modelo y tabla sin columnas SSO. La relación vive en
`sso_identities`; `IdentityLinks::find/link` centraliza resolución y vinculación explícita.
No sincroniza atributos ni credenciales de usuarios vinculados. `greenfield` ya incluye
una fábrica para el esquema Laravel convencional. Para altas personalizadas selecciona
`automatic` y configura `create_user` con una clase invocable o callable del anfitrión que devuelva un
usuario persistido, del modelo configurado y en la misma conexión. La fábrica recibe
`Identity`, no tokens ni contraseñas. Prefiere clase invocable para `config:cache`.
El predeterminado ejecuta fábrica, relación y `allowed` dentro de la transacción; un
rechazo revierte los cambios. Evita efectos externos dentro de esa fábrica; los reintentos
pueden invocarla varias veces en transacciones que luego se revierten. Una restricción
única ajena al mapeo no se trata como una carrera de identidad reintentable. La fábrica
greenfield convierte una colisión de identificador local en rechazo, sin adoptar otra cuenta.
Consulta [instalación y ejemplo](INSTALL-CLIENT.md).

Los servicios `GlobalSessions` y `LogoutDeliveries` se resuelven por DI. No son APIs
administrativas HTTP. Conserva autorización propia al invocarlos; sus transiciones
emiten ahora `AuditEvent` con referencias HMAC después de commit. Conecta un destino
privado según [el catálogo y las garantías de auditoría](AUDIT-OPERATIONS.md).
Las vistas `sso-server::consent` y `sso-server::logout` se pueden sobrescribir siguiendo la
convención Laravel resources/views/vendor/sso-server. Conserva CSRF y transacciones opacas.
La vista `sso-server::devices` expone administración propia con contraseña reciente.
El provider incorpora el middleware al kernel web y escucha Login/Validated, ambos
eventos de logout nativos y PasswordReset. No sustituyas la restauración por un login
que avance auth_time; usa la política documentada en REMEMBER-DEVICES.md.

El OP ofrece atributos normales profile/email desde el Eloquent central mediante
`sso-server.claim_attributes`. El mapeo puede apuntar a columnas o atributos JSON casteados;
omite valores ausentes y no carga relaciones. No se agregan datos personales de relleno.
La selección OIDC claims permanece ligada al código y al token, y se filtra por destino y
value/values. No admite solicitar passwords, roles ni propiedades arbitrarias del modelo.
Adaptadores de claims y proveedores sin Eloquent se evaluarán después; no existe una
interfaz universal prometida para ellos. Nunca aceptar atributos arbitrarios de un request.

La composición de OidcServer usa clases Bridge de Passport y las interfaces de League;
Laravel no garantiza toda esa superficie como API documentada. Las pruebas de grants
reales deben pasar tras cualquier cambio de versión. No modificar vendor para extender.
