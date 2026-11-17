<!doctype html>
<html lang="es">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cuenta central</title>
<h1>{{ $interaction === 'register' ? 'Crear cuenta central' : 'Establecer contraseña central' }}</h1>
@if (session('status'))<p role="status">{{ session('status') }}</p>@endif
@if ($errors->any())<p role="alert">No se pudo completar la solicitud. Revisa los campos o solicita un enlace nuevo.</p>@endif
<form method="POST" action="{{ $interaction === 'register' ? route('sso-server.auth.register') : ($interaction === 'forgot_password' ? route('sso-server.auth.email') : route('sso-server.auth.reset')) }}">
    @csrf
    @if ($interaction === 'register')<p><label>Nombre <input name="name" required autocomplete="name" value="{{ old('name') }}"></label></p>@endif
    <p><label>Email <input type="email" name="email" required autocomplete="email" value="{{ old('email', $email ?? '') }}"></label></p>
    @if ($interaction !== 'forgot_password')
        @if ($interaction === 'reset_password')<input type="hidden" name="token" value="{{ $token }}">@endif
        <p><label>Contraseña nueva <input type="password" name="password" required autocomplete="new-password" minlength="{{ config('sso-server.password_min_length') }}"></label></p>
        <p><label>Confirmar contraseña <input type="password" name="password_confirmation" required autocomplete="new-password"></label></p>
    @endif
    <button type="submit">{{ $interaction === 'forgot_password' ? 'Enviar instrucciones' : 'Guardar' }}</button>
</form>
<p><a href="{{ app(\LaravelSso\Server\Login::class)->url() }}">Ir al login</a></p>
</html>
