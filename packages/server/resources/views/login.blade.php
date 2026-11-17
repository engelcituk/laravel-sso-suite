<!doctype html>
<html lang="es">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Iniciar sesión</title>
<h1>Iniciar sesión</h1>
@if (session('status'))<p role="status">{{ session('status') }}</p>@endif
@if ($errors->any())<p role="alert">Las credenciales no son válidas.</p>@endif
<form method="POST" action="{{ route('sso-server.auth.authenticate') }}">
    @csrf
    <p><label>Usuario <input name="{{ $field }}" required autocomplete="username" value="{{ old($field) }}"></label></p>
    <p><label>Contraseña <input type="password" name="password" required autocomplete="current-password"></label></p>
    <p><label><input type="checkbox" name="remember" value="1"> Recordarme en este dispositivo</label></p>
    <button type="submit">Entrar</button>
</form>
@if (config('sso-server.password_reset'))<p><a href="{{ route('sso-server.auth.forgot') }}">Olvidé mi contraseña</a></p>@endif
@if (config('sso-server.registration') === 'open')<p><a href="{{ route('sso-server.auth.registration') }}">Crear cuenta central</a></p>@endif
</html>
