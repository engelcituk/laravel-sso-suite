<!doctype html>
<html lang="es"><meta charset="utf-8"><title>Identidad central</title>
<h1>Identidad central</h1>
@if ($errors->any()) <p role="alert">Credenciales inválidas.</p> @endif
<form method="post" action="/login">
    @csrf
    <label>Correo <input name="email" type="email" autocomplete="username" required></label>
    <label>Contraseña <input name="password" type="password" autocomplete="current-password" required></label>
    @if (config('sso-server.remember_enabled'))
        <label><input name="remember" type="checkbox" value="1"> Recordarme en este dispositivo</label>
    @endif
    <button>Entrar</button>
</form></html>
