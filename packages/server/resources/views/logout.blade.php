<!doctype html>
<html lang="es"><meta charset="utf-8"><title>Cerrar sesión global</title>
<h1>Cerrar esta sesión en todas las aplicaciones</h1>
<p>Las otras aplicaciones rechazarán las siguientes operaciones según su política de verificación.</p>
<form method="post" action="{{ route('sso-server.logout') }}">
    @csrf
    <input type="hidden" name="transaction" value="{{ $transaction }}">
    <button>Cerrar sesión global</button>
</form></html>
