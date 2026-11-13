# Integración Laravel Auth

El cliente aprovisiona un modelo Eloquent y llama a `SessionGuard::login($user, false)`.
`Auth::id()` es la clave **local**, mientras `Auth::user()->sso_subject` identifica al sujeto
central y siempre se interpreta junto con `sso_issuer`.

Funcionan `Auth::check()`, `auth()->user()`, el middleware auth, relaciones Eloquent,
Gates y Policies. Usa el guard configurado si tu aplicación tiene varios guards. No se
instala un UserProvider remoto ni se cambian silenciosamente tus guards/rutas.

Para autoría de registros conserva claves foráneas a users.id y relaciones belongsTo.
Revocar una sesión no elimina el usuario local ni rompe relaciones históricas.

`SsoOnly` rechaza asignar password, devuelve una contraseña vacía y deshabilita remember
tokens locales. Retira las rutas de registro, password reset y login por contraseña del
guard SSO. Si necesitas otros tipos de identidad utiliza guards/modelos separados y
protege cada grupo con su estrategia correspondiente. No uses Auth::attempt en el RP.

Aplica `sso.session` junto con auth: comprueba el índice local **en cada request** y consulta
la vigencia central conforme al intervalo. Las operaciones sensibles usan `strict`.
Las solicitudes ya iniciadas antes de una revocación no se pueden cancelar retroactivamente.
