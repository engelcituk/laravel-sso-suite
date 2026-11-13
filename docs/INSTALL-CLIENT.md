# Instalar el cliente

Agrega el repositorio path de [la guía del servidor](INSTALL-SERVER.md), con versiones
`laravel-sso/client: 0.1.x-dev` y `laravel-sso/contracts: 0.1.x-dev`, y ejecuta:

```sh
composer require laravel-sso/client:0.1.x-dev
php artisan vendor:publish --tag=sso-client-config
php artisan vendor:publish --tag=sso-client-migrations
php artisan migrate
```

Este paquete no instala Passport. Las migraciones sólo agregan el índice de sesiones y
recibos de logout; no crean ni modifican tu tabla users. Publica las migraciones una sola vez.
Modifica o crea mediante una migración propia el modelo local, con campos:

```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('sso_issuer', 191);
    $table->string('sso_subject', 191);
    $table->unique(['sso_issuer', 'sso_subject']);
    $table->string('name')->nullable();
    $table->string('email')->nullable();
    $table->timestamps();
});
```

No agregues password ni remember_token. En tablas existentes prepara una migración
controlada, sin eliminar información ni vincular cuentas por coincidencia de correo.
Tu modelo extiende `Illuminate\Foundation\Auth\User`, usa `LaravelSso\Client\SsoOnly`
y permite creación de `sso_issuer`, `sso_subject`, `name` y `email` mediante fillable.
Configura `sso-client.user_model` con su clase real y el proveedor Eloquent del guard.

```dotenv
SSO_CLIENT_ENABLED=true
SSO_ISSUER=https://identity.example.org
SSO_CLIENT_ID=identificador-registrado
SSO_CLIENT_SECRET=secreto-del-cliente
SSO_REDIRECT_URI=https://one.example.org/sso/callback
SSO_SESSION_STATUS_URL=https://identity.example.org/oidc/session
```

En `sso-client.allowed_urls` autoriza explícitamente discovery, authorize, token, jwks,
session y logout de ese issuer, con las URLs exactas. No se siguen redirecciones HTTP
del transporte servidor-a-servidor. El proveedor nunca se toma de un parámetro del visitante.
`sso:doctor` comprueba discovery y conectividad sin mostrar secretos.
El callback verifica iss si el OP lo anuncia y at_hash si aparece en el ID Token.
Puedes fijar sso-client.max_age (segundos, incluido 0) para solicitar reautenticación y
validar auth_time firmado; no se acepta un max_age arbitrario desde parámetros del visitante.

El guard debe ser SessionGuard con proveedor Eloquent local. Usa sesiones de servidor
(`database`, `file` o Redis); el driver cookie se rechaza. Protege su almacenamiento:
el access token y el ID Token se mantienen exclusivamente dentro de esa sesión.

Por defecto `provision=restricted`: la identidad debe estar aprobada/precreada mediante
un procedimiento de tu aplicación. `automatic` crea usuarios sin privilegios y sólo
sincroniza nombre/email. El callback `allowed` puede rechazar acceso local; para políticas
persistentes de acceso aplica además Gates/Policies en cada recurso protegido.

```php
Route::middleware(['auth', 'sso.session'])->group(function () {
    Route::get('/dashboard', fn () => response()->json(['local_id' => auth()->id()]));
});
Route::middleware(['auth', 'sso.session:strict'])->post('/sensitive-operation', function () {
    Gate::authorize('perform-sensitive-operation'); // Define esta política en tu aplicación.
    return response()->noContent();
});
```

`auth` conserva la integración Laravel. **`sso.session` es obligatorio en recursos SSO**:
usar únicamente `auth` no comprueba revocaciones globales. Configura la redirección de
invitados a `/sso/login` en tu `bootstrap/app.php`. El callback regenera la sesión y usa
un destino de configuración fijo (`after_login`), sin aceptar destinos del visitante.

El formulario de logout envía POST a `route('sso.logout')` e incluye `@csrf`.
La ruta back-channel se registra sin middleware web/CSRF: su autenticación es la firma
del Logout Token, su audiencia, tiempos y recibo persistente de un uso.
