<!doctype html>
<html lang="es"><meta charset="utf-8"><title>Autorizar acceso</title>
<h1>Autorizar acceso a tu identidad</h1>
<p>La aplicación solicita: {{ implode(', ', $scopes) }}.</p>
<form method="post" action="{{ route('sso-server.consent') }}">
    @csrf
    <input type="hidden" name="transaction" value="{{ $transaction }}">
    <button name="approve" value="1">Autorizar</button>
    <button name="approve" value="0">Rechazar</button>
</form></html>
