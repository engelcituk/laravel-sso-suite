# Integración Laravel Auth

El cliente utiliza el SessionGuard y el proveedor Eloquent del anfitrión. Con
`SSO_CLIENT_ENABLED=false` siguen funcionando el login por contraseña, registro,
recuperación y Recordarme nativos. Con `true`, el callback OIDC resuelve un usuario
local vinculado y ejecuta `SessionGuard::login($user, false)` regenerando la sesión.

`Auth::id()` siempre es la clave local. Funcionan `Auth::check()`, `auth()->user()`,
relaciones, Gates y Policies. No se instala un proveedor remoto ni se reescriben guards.
Las identidades centrales se guardan en `sso_identities`, no en columnas del usuario.
Usa `IdentityLinks::find($issuer, $subject)` desde código autorizado para resolver una
identidad; durante una sesión SSO validada, `session('sso.identity')` contiene su contexto.
Interpreta siempre `sub` junto con `iss`; no busques cuentas por email.

El modelo puede ser tu `User` convencional con password y remember_token. La vinculación
no cambia su perfil, hash, recaller, permisos ni ID; las relaciones históricas siguen
apuntando al mismo usuario. El paquete no crea, elimina ni altera la tabla del anfitrión.
Para altas automáticas se exige una fábrica explícita del anfitrión; para usuarios ya
existentes basta una vinculación aprobada mediante `sso:link-user`.

Integra `sso.local` en las rutas nativas de autenticación y `auth` + `sso.session` en
los recursos protegidos. Configura la redirección de invitados según el flag. La
[guía de instalación](INSTALL-CLIENT.md) detalla orden y ejemplos. La integración
se realiza una vez; el paquete no intercepta rutas propias que no lo utilicen.

Cambiar de modo exige autenticación nueva: una sesión local no acredita login OIDC, y
una sesión SSO no concede acceso nativo al apagar el flag. Los cierres iniciados por SSO
usan `logoutCurrentDevice()` para conservar `remember_token`; el ciclo del recaller
nativo sigue perteneciendo a tu Auth local. No se comparten contraseñas o cookies con el OP.

`SsoOnly` es una opción para modelos deliberadamente exclusivos de SSO: devuelve una
contraseña vacía e inhabilita el recaller local. No modifica el esquema ni borra valores
almacenados. No lo añadas a un modelo que deba soportar los dos modos.

Con SSO activo, `sso.session` comprueba el índice local por petición y consulta la
vigencia central conforme al intervalo. `strict` comprueba siempre al OP y falla cerrado.
Las peticiones ya iniciadas no pueden cancelarse retroactivamente. Apagar SSO debe ser
un cambio de configuración autorizado; una caída del OP no conmuta automáticamente.
