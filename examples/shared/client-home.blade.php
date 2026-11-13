<!doctype html>
<html lang="es"><meta charset="utf-8"><title>{{ config('app.name') }}</title>
<h1>{{ config('app.name') }}</h1>
<a href="{{ route('sso.login') }}">Acceder con identidad central</a>
<a href="/me">Mi identidad</a> <a href="/reports">Informes con ACL local</a>
<form method="post" action="{{ route('sso.logout') }}">@csrf <button>Cerrar sesión global</button></form>
</html>
