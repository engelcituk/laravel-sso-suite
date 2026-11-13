<!doctype html>
<html lang="es"><meta charset="utf-8"><title>Cerrar sesión global</title>
<h1>Cerrar esta sesión en todas las aplicaciones</h1>
@if ($account !== null) <p>Cuenta: {{ $account }}.</p> @endif
@if ($clientName !== null) <p>Solicitud de {{ $clientName }}.</p> @endif
<p>Las otras aplicaciones rechazarán las siguientes operaciones según su política de verificación.</p>
<form method="post" action="{{ route('sso-server.logout') }}">
    @csrf
    <input type="hidden" name="transaction" value="{{ $transaction }}">
    <button name="approve" value="1">Cerrar sesión global</button>
    <button name="approve" value="0">Cancelar</button>
</form></html>
