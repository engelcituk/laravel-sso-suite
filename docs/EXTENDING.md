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

El modelo de claims OP actual ofrece name/email/email_verified desde el Eloquent central.
Adaptadores de claims y proveedores sin Eloquent se evaluarán después; no existe una
interfaz universal prometida para ellos. Nunca aceptar atributos arbitrarios de un request.

La composición de OidcServer usa clases Bridge de Passport y las interfaces de League;
Laravel no garantiza toda esa superficie como API documentada. Las pruebas de grants
reales deben pasar tras cualquier cambio de versión. No modificar vendor para extender.
