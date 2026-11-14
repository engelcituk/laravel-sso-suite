<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tus dispositivos</title>
    <style>
        :root { color-scheme: light; color: #202020; background: #fff; }
        body { margin: 0; line-height: 1.5; }
        main { max-width: 52rem; padding: 2rem 1.25rem 4rem; margin: auto; }
        h1 { margin: 0 0 .5rem; font-size: 2rem; } h2 { font-size: 1.25rem; margin: 0; }
        p { max-width: 70ch; } a { color: #174a85; text-underline-offset: .2em; }
        input, button { font: inherit; max-width: 100%; box-sizing: border-box; }
        input { padding: .5rem; } button { padding: .5rem .8rem; cursor: pointer; }
        :focus-visible { outline: 3px solid #174a85; outline-offset: 3px; }
        .confirmation, .bulk { margin-top: 2rem; padding: 1.25rem 0; border-top: 1px solid #b8b8b8; }
        .devices { padding: 0; margin: 1.5rem 0 0; list-style: none; }
        .device { padding: 1.5rem 0; border-top: 1px solid #b8b8b8; overflow-wrap: anywhere; }
        .device-header, .controls { display: flex; flex-wrap: wrap; gap: .75rem 1.25rem; align-items: end; }
        .device-header { align-items: baseline; justify-content: space-between; }
        .controls { margin-top: 1rem; } .controls label { display: flex; flex-direction: column; gap: .25rem; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: .25rem 1rem; margin: 1rem 0; }
        dd { margin: 0; } .status { font-weight: bold; } .muted { color: #555; }
        .feedback { padding: .75rem 0; font-weight: bold; } .error { color: #8b1a13; }
        details { margin-top: .75rem; } summary { cursor: pointer; } nav { display: flex; gap: 1.5rem; margin-top: 1.5rem; }
        @media (max-width: 36rem) { dl { grid-template-columns: 1fr; gap: 0; } dd { margin-bottom: .6rem; } .controls { align-items: stretch; } .controls form { flex-basis: 100%; } .controls input { width: 100%; } }
    </style>
</head>
<body>
<main>
    <h1>Tus dispositivos</h1>
    <p>Revisa los navegadores con acceso a tu cuenta. Puedes cambiar sus nombres o retirar su acceso, incluido Recordarme.</p>
    @if (session('status')) <p role="status" class="feedback">{{ session('status') }}</p> @endif
    @if ($errors->any()) <div role="alert" class="error"><p>No se pudo completar la operación.</p><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif

    <section class="confirmation" aria-labelledby="confirm-heading">
        <h2 id="confirm-heading">Confirma que eres tú</h2>
        @if ($confirmed)
            <p>Tu contraseña se ha confirmado recientemente. Los controles están disponibles durante unos minutos.</p>
        @else
            <p>Introduce tu contraseña para cambiar nombres o cerrar sesiones.</p>
            <form method="post" action="{{ route('sso-server.devices.confirm') }}" class="controls">
                @csrf
                <label>Contraseña actual <input name="password" type="password" autocomplete="current-password" required maxlength="1024"></label>
                <button type="submit">Confirmar contraseña</button>
            </form>
        @endif
    </section>

    <ul class="devices" aria-label="Dispositivos de tu cuenta">
        @forelse ($rows as $device)
            @php($active = $device->revoked_at === null && $device->expires_at > time())
            <li class="device">
                <div class="device-header">
                    <h2>{{ $device->label }}</h2>
                    <span class="status">{{ $device->id === $current ? 'Este navegador' : ($active ? 'Con acceso' : 'Acceso finalizado') }}</span>
                </div>
                <dl>
                    <dt>Última actividad</dt><dd><time datetime="{{ gmdate(DATE_ATOM, $device->last_seen) }}">{{ gmdate('d/m/Y H:i', $device->last_seen) }} UTC</time></dd>
                    <dt>Autenticación inicial</dt><dd>{{ gmdate('d/m/Y H:i', $device->auth_time) }} UTC</dd>
                    <dt>Recordarme</dt><dd>{{ $active && $device->remember_until !== null && config('sso-server.remember_enabled') ? 'Hasta '.gmdate('d/m/Y H:i', $device->remember_until).' UTC' : 'Sin acceso guardado' }}</dd>
                    @if ($device->ip_address !== null)<dt>Dirección IP</dt><dd>{{ $device->ip_address }}</dd>@endif
                </dl>
                <details><summary>Detalles del navegador</summary><p class="muted">{{ $device->user_agent !== '' ? $device->user_agent : 'El navegador no informó sus detalles.' }}</p></details>
                @if ($confirmed && $active)
                    <div class="controls">
                        <form method="post" action="{{ route('sso-server.devices.label', $device->id) }}" class="controls">
                            @csrf
                            <label>Nombre del dispositivo <input name="label" value="{{ $device->label }}" maxlength="100" required></label>
                            <button type="submit" aria-label="Guardar nombre de {{ $device->label }}">Guardar nombre</button>
                        </form>
                        <form method="post" action="{{ route('sso-server.devices.revoke', $device->id) }}">
                            @csrf
                            @php($closeLabel = $device->id === $current ? 'Cerrar esta sesión' : 'Cerrar sesión')
                            <button type="submit" aria-label="{{ $closeLabel }} de {{ $device->label }}">{{ $closeLabel }}</button>
                        </form>
                    </div>
                @endif
            </li>
        @empty
            <li class="device"><p>No hay dispositivos registrados en esta cuenta.</p></li>
        @endforelse
    </ul>
    <nav aria-label="Páginas de dispositivos">
        @if ($rows->previousPageUrl())<a href="{{ $rows->previousPageUrl() }}">Anteriores</a>@endif
        @if ($rows->nextPageUrl())<a href="{{ $rows->nextPageUrl() }}">Siguientes</a>@endif
    </nav>
    @if ($confirmed)
        <section class="bulk" aria-labelledby="close-heading">
            <h2 id="close-heading">Cerrar más sesiones</h2>
            <p>Se retirará también el acceso a las aplicaciones vinculadas. Cerrar todas las sesiones incluye este navegador.</p>
            <div class="controls">
                <form method="post" action="{{ route('sso-server.devices.others') }}">@csrf<button type="submit">Cerrar los otros dispositivos</button></form>
                <form method="post" action="{{ route('sso-server.devices.all') }}">@csrf<button type="submit">Cerrar todas las sesiones</button></form>
            </div>
        </section>
    @endif
</main>
</body>
</html>
