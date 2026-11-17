# Instalar los paquetes desde GitHub

Composer descarga paquetes independientes desde el mismo repositorio GitHub. La rama
`main` conserva el código y la rama `composer` contiene el índice y los ZIP generados.
No necesitas clonar el repositorio junto a tus proyectos, crear tags, publicar releases
ni registrar los paquetes en Packagist.

## 1. Configura el origen en cada Laravel

Desde la carpeta del proyecto Laravel:

```sh
composer config repositories.laravel-sso composer https://raw.githubusercontent.com/engelcituk/laravel-sso-suite/composer
```

Este comando escribe un repositorio **remoto** en el `composer.json` de ese proyecto.
Se configura tanto en el servidor como en cada cliente; las dependencias no pueden
añadir repositorios al proyecto anfitrión. No cambies `minimum-stability` global.

Si antes configuraste el ejemplo local `path`, elimina esa entrada de `repositories`
del proyecto: un repositorio local con prioridad puede ocultar el remoto. Si nunca
instalaste el paquete, basta con el comando anterior.

## 2. Instala el papel de cada proyecto

En el servidor de identidad:

```sh
composer require laravel-sso/server:0.1.x-dev laravel-sso/contracts:0.1.x-dev --prefer-dist
php artisan sso:install-server --configure --migrate --keys
php artisan sso:doctor-server
```

En la aplicación cliente, después de registrarla en el servidor:

```sh
composer require laravel-sso/client:0.1.x-dev laravel-sso/contracts:0.1.x-dev --prefer-dist
php artisan sso:install-client --configure --migrate
php artisan sso:doctor
```

`contracts` se requiere explícitamente para permitir esa dependencia de desarrollo sin
abrir todos los paquetes a estabilidad `dev`. El cliente conserva su independencia:
no instala `laravel-sso/server` ni Passport. La descarga no crea cuentas ni configura
automáticamente SSO; los comandos Artisan realizan las acciones indicadas por sus opciones.

Continúa con la [guía rápida](QUICKSTART.md) para registrar la aplicación, crear una
cuenta central, asignarle acceso y comprobar el primer login.

## Desarrollo y actualizaciones

`0.1.x-dev` es una línea experimental de desarrollo, no una versión estable ni un tag.
Cada actualización del índice apunta a una revisión comprobada del código. Guarda el
`composer.lock` de tu aplicación: conserva las referencias y checksums de los ZIP.
`composer install` reutiliza lo fijado en ese lockfile; no selecciona automáticamente
el índice más reciente.

Para actualizar deliberadamente un servidor:

```sh
composer update laravel-sso/server laravel-sso/contracts --with-dependencies --prefer-dist
```

En un cliente usa `laravel-sso/client` en lugar de `laravel-sso/server`. Revisa los
cambios y valida la aplicación antes de aceptar el lockfile actualizado. Una migración
inicial ya registrada no se reaplica por actualizar Composer; las bases previas conservan
el procedimiento de actualización de esquema descrito en las guías.

## Cómo se construye y actualiza

El job `github-distribution` de la matriz `quality` se ejecuta sólo para `main`, después
de aprobar todos los perfiles PHP, manifiestos/instalación y concurrencia SQL. También
puede iniciarse la matriz con `workflow_dispatch`. Las publicaciones se serializan y
se descarta una revisión si `main` ya avanzó. Los pull requests no reciben permiso de escritura.

1. `tools/build-distribution.php build` lee únicamente los archivos del commit indicado,
   incluyendo runtime, configuración, vistas, licencia y una migración por paquete Laravel.
   Excluye vendor, ejemplos, pruebas, `.env`, bases, claves y logs.
2. Los ZIP y su inventario se guardan en `composer/dist/COMMIT_FUENTE/`.
3. Se confirma primero el contenido de los archivos y luego se genera `packages.json`
   con URLs al **commit de los archivos**, no a una rama mutable. El índice registra
   checksum SHA-1 para Composer y el inventario añade SHA-256.
4. Se actualiza únicamente la rama `composer` mediante push normal. Se conserva su
   historial, necesario para que los lockfiles antiguos sigan descargando sus archivos.

Los ZIP mantienen `composer.json`, `src/`, `config/`, vistas y migración en su ubicación
normal. Laravel descubre los providers automáticamente. No se clona el monorepo dentro
de cada paquete instalado ni se requiere un instalador/plugin Composer especial.

Para verificar la construcción en una copia de desarrollo, con PHP 8.3+ y ZIP:

```sh
mkdir -p .runtime/distribution-local
SOURCE=$(git rev-parse HEAD)
php tools/build-distribution.php build --source="$SOURCE" --output=.runtime/distribution-local
php tools/build-distribution.php index --source="$SOURCE" --output=.runtime/distribution-local --archives-ref="$SOURCE"
php tools/verify-distribution.php .runtime/distribution-local /ruta/a/composer.phar
```

Este ejemplo de mantenedor usa una terminal Bash. El verificador sustituye las URLs
por un servidor loopback temporal: ese índice de ensayo no se publica. Instala dos
hosts descartables bajo `.runtime`, verifica dependencias, autoload, descubrimiento,
configuración, migración y reinstalación. No instala nada en los proyectos del usuario.

## Acceso y diagnóstico

La URL documentada presupone un repositorio público y la rama `composer` inicializada.
Si devuelve 404, comprueba la ejecución de distribución y la existencia de esa rama.
Las credenciales del repositorio no deben incluirse en `composer.json` ni en las URLs.
Esta guía no promete acceso anónimo a un repositorio privado.

La automatización necesita `contents: write` para esa rama; respeta las políticas de la
organización y no usa force-push. Si las reglas bloquean el push, la ejecución falla y
conserva la distribución anterior. No se crean repositorios adicionales, tags o releases.

## Evidencia de la distribución inicial

La rama `composer` está inicializada con los paquetes del commit
`fba4d6fa7446e0e52adcd92048b603a02f5357f7`. La descarga real por HTTPS y la prueba
loopback pasan **31 controles por recorrido**, con dos hosts Laravel descartables
en cada uno. Se verifican checksum, estructura, reconstrucción repetible, rechazo de
archivos alterados, resolución independiente, autoload, descubrimiento Laravel,
configuración/migración y reinstalación. El cliente no recibe Passport ni el servidor.
Los instaladores se invocan sin activar SSO ni migrar las aplicaciones del usuario.

La suite general conserva **320 pruebas / 1677 aserciones** en PHP 8.3 y Pint global
aprobado. El workflow de `main` actualiza la rama `composer` después de aprobar la
matriz de calidad. La distribución inicial ya está disponible en esa rama remota.
[Resultados y referencias de la validación inicial](../conformance/github-distribution-results.json);
ese informe conserva el estado anterior a la integración del workflow en `main`.

El formato sigue el [repositorio Composer](https://getcomposer.org/doc/05-repositories.md#composer)
y sus [metadatos de distribución](https://getcomposer.org/doc/04-schema.md#dist).
