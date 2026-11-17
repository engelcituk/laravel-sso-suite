# Docker y suite OpenID Foundation en Windows

2026-11-13. En el entorno Windows verificado, Docker quedó operativo tras habilitar
su virtualización y reiniciar el sistema. Se ejecutaron los cinco planes; ver [resultados](OIDC-CONFORMANCE-RESULTS.md).
El código Laravel, los ejemplos y las pruebas locales siguen disponibles con Herd/Laragon.

## Entorno verificado

- Docker Desktop 4.99.0 (245822), CLI/Engine Linux 29.9.3, Compose v5.7.0.
- WSL 3.0.5, kernel 6.18.58.1; VirtualMachinePlatform y WSL activos.
- Suite: Temurin 21.0.13+9, MongoDB 6.0.13, nginx 1.27.3.
- OP del laboratorio: contenedor Linux PHP 8.3.36 y SQLite 3.40.1.
- Pruebas de paquetes/E2E: Windows y PHP Herd 8.3.36/8.4.27/8.5.12.
- Fuente oficial sin cambios: `daca6ad2068468d42557ea1152f899cc3ac01a10`.
- JAR SHA256: `8b77b18cef94f0aa21505f3d5ecdb6cb5ec7f90c37f61988bc00068a406eba0f`.

La instalación inicial quedó bloqueada por VirtualMachinePlatform hasta el primer reinicio.
Ese bloqueo está resuelto. Los timeouts intermitentes del OP de Windows fueron una
incidencia posterior del laboratorio; se conservan sus logs y no se presentaron como aprobados.

## Reproducción

Con Docker Desktop iniciado, desde este repositorio:

```powershell
.\tools\oidf-docker.ps1 -Action Status
.\tools\oidf-docker.ps1 -Action Start
.\tools\oidf-docker.ps1 -Action Run -Mode compatibility -Label oidc
```

`Start` verifica la revisión oficial, compila el JAR si falta, prepara un OP descartable
con PHP de Herd y construye localmente la imagen de PHP. Copia código, vendor y sólo los
secretos/bases del fixture a `laravel-sso-oidf-op-app`, un volumen Linux. Excluye junctions
recursivas de Testbench y reconstruye enlaces de los tres paquetes. No copia ni modifica
las bases o credenciales de los ejemplos. No instala dependencias de Composer distintas.
Antes de extraer la copia, sustituye los archivos de migración de las carpetas gestionadas
del volumen para que no sobrevivan migraciones retiradas del repositorio. Conserva las
bases, su historial y los informes originales.

El OP atiende con cuatro procesos PHP en la red Docker. El runner y su worker de logout
usan la misma base SQLite. `Run` prepara la política elegida dentro del contenedor y copia
sus informes originales al directorio .runtime/conformance de Windows. No ejecutes
`tools/oidf-run.php` directamente en Windows: no compartiría la base del OP de Docker.

`compatibility` activa la excepción autorizada sólo para dos RPs confidenciales de prueba.
`strict` conserva S256 para todos y sirve como evaluación negativa de los flujos Basic sin
PKCE. El runner devuelve 1 si hay REVIEW/WARNING además de fallos: consulta los estados
por módulo, no interpretes ese código por sí solo como un FAILED.

Para revisar los últimos informes sin modificar estados oficiales:

```powershell
$resultDir = Get-ChildItem .runtime/conformance -Directory -Filter 'official-oidc-*' | Sort-Object Name | Select-Object -Last 1
$summaryPath = Join-Path $resultDir.FullName 'summary.json'
php tools/oidf-review.php $summaryPath --export
php tools/oidf-summary.php $summaryPath
docker exec laravel-sso-oidf-identity-php-1 php /app/tools/oidf-transport.php
.\tools\oidf-docker.ps1 -Action Stop
```

La auditoría contrasta DOM, formularios POST, origen de acciones, CSRF, errores 400 y las
capacidades de discovery. Guarda un informe sin HTML/secretos y mantiene REVIEW/SKIPPED.
El chequeo de transporte hace 80 peticiones con cuatro simultáneas; no es certificación.
`Stop` baja sólo este proyecto Compose, conservando Mongo, exports y el volumen del OP.
No quedan procesos PHP de laboratorio escuchando en Windows.

## Red, claves y almacenamiento

Sólo la UI/API oficial expone `127.0.0.1:8443`. El OP HTTPS `identity.sso.test:9443`, PHP
8000 y Mongo están dentro de Docker; no publican puertos. No hay salto host.docker.internal
hacia PHP de Windows. No se abren túneles ni hosting externo.

La CA privada y su certificado SAN se limitan al laboratorio. PHP usa verify/cacert y Java
su truststore privado; no se añade una raíz global de Windows. Las claves copiadas desde
Windows se restringen a 0600 en Linux, manteniendo activas las validaciones de Passport.
Los certificados duran 30 días. Al repetir después de su vencimiento deben renovarse.

El build Java usa el volumen `laravel-sso-oidf-source-daca6ad20684` y el caché local
`.runtime/maven-cache`; la compilación inicial en Linux terminó BUILD SUCCESS en 6:21.
La imagen PHP se construye desde conformance/php; no contiene el proyecto en su Dockerfile
ni se sube al registro. La instantánea privada op-app.tar está ignorada por Git.

Originales finales: `.runtime/conformance/official-linux-final-20261113-211549/`.
Se conservan los ensayos de Windows, la primera incidencia de permisos Unix, logs de build,
preflight y el chequeo de transporte. No se enviaron datos a la instancia pública de
OpenID Foundation ni se publicaron paquetes, imágenes o resultados.

Fuentes: [Docker Windows](https://docs.docker.com/desktop/setup/install/windows-install/),
[WSL](https://learn.microsoft.com/en-us/windows/wsl/install),
[build oficial](https://gitlab.com/openid/conformance-suite/-/wikis/Developers/Build-%26-Run).
