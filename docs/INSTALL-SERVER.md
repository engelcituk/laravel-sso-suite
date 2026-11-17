# Instalar el servidor central

**Parte de un Laravel nuevo destinado a ser el servidor central. No necesita un starter kit.**
Este Laravel guarda las cuentas centrales y autentica a las personas. Cada aplicación
cliente conserva sus usuarios, contraseñas y permisos independientes.
Este es el escenario prioritario del paquete. Las instrucciones para un login o una
instalación existente son compatibilidad adicional y puedes omitirlas en greenfield.

## 1. Prepara un Laravel normal

Necesitas Laravel 13, PHP 8.3+, Composer 2, OpenSSL, una DB configurada y Auth con
SessionGuard/proveedor Eloquent. En un Laravel limpio conserva su modelo `User` normal:
no agregues traits SSO, columnas `is_active` ni campos de identidad remota.

Prepara `.env`, APP_KEY y tablas base mediante el procedimiento normal de tu aplicación.
El paquete no crea cuentas ni ejecuta migraciones pendientes del anfitrión. Si el Laravel
ya existe, conserva su configuración y datos. Para el ejemplo usaremos
`APP_URL=https://identidad.test`, un origen dedicado sin subdirectorio.

Como aún no hay versión publicada, agrega el repositorio Composer path de
[la guía rápida, paso 1](QUICKSTART.md#1-permite-que-composer-encuentre-los-paquetes).

## 2. Ejecuta la instalación

```sh
composer require laravel-sso/server:0.1.x-dev laravel-sso/contracts:0.1.x-dev
php artisan sso:install-server --configure --migrate --keys
```

Responde `https://identidad.test` cuando pida **Public issuer URL**. También puedes indicarlo:

```sh
php artisan sso:install-server --configure --issuer=https://identidad.test --migrate --keys
```

| Opción | Acción explícita |
| --- | --- |
| Sin opciones | Copia sólo configuración y única migración faltantes; no activa SSO |
| `--configure` | Escribe sólo variables `SSO_*` en el `.env` existente y activa el servidor; limpia config/route cache |
| `--migrate` | Aplica la única migración SSO y prepara las migraciones de Passport si aún no existe `oauth_clients` |
| `--keys` | Crea claves OAuth/OIDC que falten; conserva las existentes |
| `--headless` | Desactiva la página opcional de login, conservando el backend JSON |
| `--allow-http` | Permite HTTP sólo fuera de producción para desarrollo |
| `--force` | Autoriza las migraciones explícitas en producción |

No cambia APP_KEY, DB, modelo, rutas del anfitrión ni atributos de usuarios. No instala
un frontend. La migración del servidor crea siete tablas SSO; Passport tiene sus propias
tablas/migraciones, independientes del límite de **una migración por paquete SSO**.
Si ya tienes Passport, conserva tablas y claves; un esquema parcial debe revisarse con
el doctor y repararse de forma controlada, no reinicializarse.

## 3. Elige cómo presentar el login

**Laravel limpio:** ya puedes abrir `/sso/auth/login`. Incluye un formulario mínimo
opcional con CSRF y limitación de intentos. Usa las cuentas existentes del guard de
Laravel. No registra usuarios ni cambia sus hashes, aunque el coste configurado haya cambiado.

**Laravel con login propio:** el modo predeterminado `auto` usa la ruta nombrada `login`.
Conserva su controlador, vistas, MFA y políticas. El POST de contraseña del paquete
queda cerrado para no crear un acceso paralelo que omita esas políticas.
Si la ruta tiene otro nombre, configura `login_route` en `config/sso-server.php` o
`SSO_SERVER_LOGIN_URL` con tu ruta de acceso.

**Vue, React, Blade o Inertia propios:** puedes conservar tu autenticación actual o
usar el backend JSON del paquete. No necesitas sustituir tu stack. Consulta el
[contrato y ejemplos de frontend](STACK-AGNOSTIC.md#frontend-propio-en-el-servidor).
`--headless` por sí solo devuelve JSON en la ruta de login: debes proporcionar una
pantalla propia si quieres una experiencia interactiva sin la vista opcional.

No se exige una columna de cuenta activa. Si tu proyecto ya tiene una booleana y quieres
aplicarla también a sesiones/tokens, configura `SSO_SERVER_ACTIVE_COLUMN=tu_columna`.
Cuando la configuras, debe existir y ser verdadera; no se ignora silenciosamente.
Los usuarios los administra el proyecto; una instalación sin cuentas necesita su proceso de altas.

## 4. Registra cada aplicación cliente

Para tu aplicación Ventas:

```sh
php artisan sso:client "Ventas" https://ventas.test/sso/callback --first-party --backchannel=https://ventas.test/sso/backchannel-logout --trust-backchannel --post-logout=https://ventas.test/
```

Guarda el ID y secreto impresos para el backend de Ventas. Sólo se muestra el secreto
una vez. Este comando registra un cliente nuevo cada vez; no lo repitas para consultar
el secreto. `--first-party` omite consentimiento para tus aplicaciones aprobadas.
Sin él se solicita consentimiento. `--trust-backchannel` agrega exactamente la URL
indicada a la lista de destinos de logout autorizados; no permite destinos arbitrarios.

Sigue después [Instalar el cliente](INSTALL-CLIENT.md).

## 5. Comprueba y opera

```sh
php artisan sso:doctor-server
php artisan schedule:list
```

El doctor debe mostrar `pass`. El scheduler descubre automáticamente entrega de logout
cada minuto y limpieza cada hora cuando SSO está activo. Ejecuta `schedule:work` en
desarrollo o el scheduler normal de Laravel en despliegue. No dupliques las tareas
si ya las programabas: puedes conservar las propias y usar `'schedule' => false`.

Usa sesiones en servidor, HTTPS y cookies seguras host-only. Cada aplicación debe tener
su APP_KEY, DB y cookie distintas. Configura TLS/proxy y permisos de claves en tu servidor;
el instalador no cambia esos ajustes. En producción reconstruye `config:cache` y
`route:cache`, y recarga los procesos persistentes después de configurar.

En una actualización, el instalador conserva los archivos copiados: compara sus claves
con la configuración del paquete. En especial una configuración anterior puede seguir
exigiendo `is_active` o una lista literal de back-channel; decide explícitamente su
política. No elimines una restricción de cuentas activa sin revisar su intención.
Una migración ya registrada no se reaplica; no uses `migrate:fresh` para actualizar.

[Referencia avanzada](INSTALL-SERVER-REFERENCE.md) · [Guía completa por stack](STACK-AGNOSTIC.md).
