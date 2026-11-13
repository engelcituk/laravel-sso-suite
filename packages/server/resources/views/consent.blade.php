<!doctype html>
<html lang="es"><meta charset="utf-8"><title>Autorizar acceso</title>
<h1>Autorizar acceso a tu identidad</h1>
<p>{{ $clientName }} solicita: {{ implode(', ', $scopes) }}.</p>
<p>Cuenta actual: {{ $account }}.</p>
<form method="post" action="{{ route('sso-server.consent') }}">
    @csrf
    <input type="hidden" name="transaction" value="{{ $transaction }}">
    <button name="approve" value="1">Autorizar</button>
    <button name="approve" value="0">Rechazar</button>
    @if ($selectAccount) <button name="approve" value="switch">Usar otra cuenta</button> @endif
</form></html>
