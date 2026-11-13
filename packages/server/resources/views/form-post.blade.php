<!doctype html>
<html lang="es"><meta charset="utf-8"><title>Continuar autenticación</title>
<form id="oidc-response" method="post" action="{{ $redirect }}">
@foreach ($parameters as $key => $value)
    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
@endforeach
    <button>Continuar</button>
</form>
<script nonce="{{ $nonce }}">document.getElementById('oidc-response').submit();</script>
</html>
