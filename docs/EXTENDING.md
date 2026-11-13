# Extensión

`LaravelSso\Contracts\Identity` es el único objeto compartido. Contiene issuer, subject,
sessionId, authenticatedAt y atributos de identidad verificados; no roles ni contraseñas.

Para personalizar aprovisionamiento implementa
`LaravelSso\Client\Contracts\ProvisionsUsers::provision(Identity): Authenticatable` y
vincula el contrato en el service provider de tu aplicación. Conserva vinculación por
issuer/subject, índice único, lista explícita de atributos y política local. El evento
`IdentityProvisioned` se emite tras aprobación y actualización, sin tokens.

El proveedor Eloquent puede utilizar tu modelo y tabla. El esquema debe ofrecer las
columnas de identidad; para otro mapeo implementa el aprovisionador y adapta explícitamente
la comprobación de sesión. La implementación predeterminada espera sso_issuer/sso_subject.

Los servicios `GlobalSessions` y `LogoutDeliveries` se resuelven por DI. No son APIs
administrativas HTTP. Agrega autorización y auditoría en las operaciones que los invoquen.
Las vistas `sso-server::consent` y `sso-server::logout` se pueden sobrescribir siguiendo la
convención Laravel resources/views/vendor/sso-server. Conserva CSRF y transacciones opacas.

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
