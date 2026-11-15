<!doctype html>
<html lang="es"><meta charset="utf-8"><title>Acceso local</title>
<h1>Acceso local</h1>
@if ($errors->any())<p role="alert">Credenciales locales inválidas.</p>@endif
<form method="post" action="{{ route('login.store') }}">
@csrf
<label>Correo <input type="email" name="email" required autocomplete="username"></label>
<label>Contraseña <input type="password" name="password" required autocomplete="current-password"></label>
<label><input type="checkbox" name="remember" value="1"> Recordarme en esta aplicación</label>
<button>Entrar</button>
</form>
</html>
