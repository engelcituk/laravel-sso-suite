<!doctype html>
<html lang="es"><meta charset="utf-8"><title>{{ config('app.name') }}</title>
<h1>{{ config('app.name') }}</h1>
<a href="{{ config('sso-client.enabled') ? route('sso.login') : route('login') }}">{{ config('sso-client.enabled') ? 'Acceder con identidad central' : 'Acceder con cuenta local' }}</a>
<a href="/me">Mi identidad</a> <a href="/reports">Informes con ACL local</a>
<form method="post" action="{{ config('sso-client.enabled') ? route('sso.logout') : route('logout') }}">@csrf <button>Cerrar sesión</button></form>
</html>
