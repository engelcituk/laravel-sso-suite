# Despliegues HTTPS, navegadores cross-site y carga

Laboratorio local aislado del 2026-11-14. Este informe distingue servidores web reales,
navegadores reales y usuarios HTTP del generador de carga. Los resultados de la campaña
se conservan con sus huellas; no se publicaron paquetes, imágenes ni evidencias externas.

## Dos despliegues comprobados

| Perfil | Web / PHP | Datos | Sesión Laravel |
| --- | --- | --- | --- |
| `fpm` | Nginx 1.28.3 + PHP 8.3.36 FPM/FastCGI | MySQL 8.4.13 | archivos de servidor cifrados |
| `apache` | Nginx TLS → Apache 2.4.68 + PHP 8.4.27 `apache2handler` | PostgreSQL 18.7 | tabla del anfitrión, payload cifrado |

Cada perfil contiene OP + dos RP, tres bases separadas, APP_KEY/cookies distintas y claves
RSA propias. No se usa `php -S` en estos despliegues. `APP_ENV=production`, `APP_DEBUG=false`,
`allow_http=false`, OPcache y `artisan optimize` (config, eventos, rutas y vistas) están activos.
Cache de aplicación en archivos. Los workers de carga/entrega usan CLI PHP 8.3.36; los SAPI
web y versiones de la tabla se verificaron por HTTP, no se dedujeron del CLI.

Los dominios son `identity.idp.test`, `one.rp-one.test`, `two.rp-two.test` y sus equivalentes
`idp-alt.test`, `rp-one-alt.test`, `rp-two-alt.test`. Son tres sitios registrables distintos
por perfil, además de orígenes distintos. El DNS sólo existe en la red Docker interna.
Ningún puerto HTTP, SQL o de navegador se publica en el host.

La CA y los certificados son privados y sólo se confían dentro de contenedores de prueba.
No se modifica el almacén de confianza Windows. HTTPS se verifica en Guzzle, Chromium,
Firefox y WebKit: el resultado final usa `ignoreHTTPSErrors=false`. Firefox instala la CA
mediante `PLAYWRIGHT_FIREFOX_POLICIES_JSON` con ruta absoluta; no se suprimen errores TLS.

Las seis aplicaciones usan una copia de la única migración inicial de su paquete. Las
tablas `users`, ACL de ejemplo y `sessions` del perfil Apache pertenecen al anfitrión de
prueba, no son migraciones incrementales del paquete. Estos despliegues Linux no usan
los SQLite de los ejemplos ni bases reales de Laragon/Herd. El E2E original se repitió
por separado sobre ejemplos locales, con identidad temporal y limpieza de sus propios datos.

## Casos de navegador

Playwright **1.65.1**, dentro de su imagen Linux oficial, ejecutó Chromium **159.0.8154.6**,
Firefox **159.0** y WebKit **27.2**. Hay seis combinaciones motor/perfil y diez comprobaciones
por combinación:

1. Navegación principal RP → OP → callback entre sitios distintos, con TLS válido.
2. Una única entrada de credenciales para entrar en ambos RP y misma identidad central.
3. ACL local sin privilegios concedidos por el login (403 en informes).
4. Tres cookies de sesión independientes y Recordarme `__Host-`, host-only, Secure,
   HttpOnly y SameSite=Lax. El informe sólo conserva atributos, nunca valores.
5. Un iframe desde el RP al OP no autentica mediante cookies Lax.
6. La navegación principal posterior al OP mantiene autenticación.
7. POST cross-site sin CSRF para revocar dispositivos: 419; la sesión legítima sigue válida.
8. Al retirar sólo la cookie de sesión central, Recordarme recupera acceso sin otra contraseña.
9. Confirmación de contraseña reciente y cambio del nombre de un dispositivo desde la pantalla real.
10. Logout confirmado y fetch desde el otro RP con resultado 401.

El OP observó `Sec-Fetch-Site: cross-site` en el probe del iframe en los tres motores.
La captura de cabeceras de las navegaciones redirigidas en Playwright Firefox no exponía
ese dato aunque el servidor lo recibía; la evidencia servidor/cookies evita confundir una
limitación de observación con un login defectuoso. Firefox usa `network.cookie.cookieBehavior=1`
para bloquear cookies de terceros; Chromium/WebKit conservan sus políticas del motor.

SameSite=Lax impide autenticación silenciosa dentro de un iframe cross-site. El flujo
soportado se valida mediante navegación principal; no se modificó la cookie a SameSite=None
para hacer pasar las pruebas. No se afirma que WebKit Linux equivalga a Safari en macOS/iOS,
ni que Chromium sea una ejecución de Chrome/Edge comerciales. No se ensayaron móviles físicos.

## Proxy e ingress

Doce casos adicionales pasaron en ambos perfiles:

- El proxy sustituye `X-Forwarded-Proto/Host/Port/For` enviados por el cliente.
- HTTPS y el host esperado se conservan en los seis endpoints de salud.
- Los cuatro RP conservan issuer y callback HTTPS configurados ante esas cabeceras.
- Un `Host` desconocido se rechaza en el ingress (444, conexión sin respuesta HTTP).

Sólo se confía en la IP privada del ingress del laboratorio. La red está aislada y el
puerto Apache HTTP/FPM no se expone. El certificado se sigue verificando en los negativos.
Las cookies, state, códigos y query strings de redirect no se exportan en esa evidencia.

## Diseño de la campaña prolongada

Duración solicitada: **1800 segundos**. Ocho usuarios HTTP independientes: cuatro por
perfil, cada uno con cuenta y cookie jar propio. Es un modelo cerrado con pausa de dos
segundos entre ciclos, no un ensayo de máxima capacidad ni una tasa de llegada garantizada.
Las solicitudes internas de token, UserInfo, estado y back-channel son reales; los conteos
del generador corresponden a sus solicitudes HTTP externas y no los suman dos veces.

El tráfico visita ambos RP con política `sso.session:strict`, completa Code/S256, vuelve
a autenticar ante vencimiento/revocación, retira periódicamente la cookie central para
ejercitar Recordarme y confirma logout global. No introduce privilegios en la identidad.
El access token/índice local vence a 120 s, el SID a 300 s y Recordarme a 900 s en el fixture.

El límite de protocolo se configura explícitamente en **6000/min/IP**, y el formulario
anfitrión en **600/min/IP**, para no convertir un emisor de carga desde NAT en una prueba
de rate limiting. No cambian los defaults del paquete ni los límites de dispositivos.
Estos valores de laboratorio no son una recomendación de dimensionamiento para producción.

Las fallas se programan sobre reloj monotónico:

| Momento solicitado | Inyección | Criterio |
| --- | --- | --- |
| 300–360 s | El ingress devuelve 503 en todos los endpoints OP del perfil FPM; los RP siguen activos | Rechazar /me en ambos RP y recuperar después; Apache continúa independiente |
| 900–1020 s | Se pausa el procesamiento de las dos outbox | Cada usuario confirma logout, el otro RP rechaza inmediatamente, se conservan entregas pendientes |
| Después de 1020 s | Se reanuda la entrega | Vaciar ambas outbox y comprobar recibos sin afectar las sesiones nuevas |

La inyección 503 no es una caída física del proceso ni una desconexión TCP silenciosa.
La pausa conserva el proceso worker y su estado durable. Los eventuales 503 de un flujo
en tránsito y el 400 del callback cuando el OP interrumpe ese intercambio se registran
por separado, sólo dentro de la ventana inyectada y su timeout; no cuentan como HTTP
exitosos. Un 400 fuera de esa ventana u otro error inesperado hace fallar la campaña.

Se toman CPU, memoria y PIDs de los cinco contenedores de servidor/SQL aproximadamente
cada 30 segundos. Los percentiles HTTP se calculan por usuario/operación; el resumen
conserva **el máximo de los p95 por usuario**, no presenta una media de p95 como percentil
global. La media se calcula con la suma de tiempos y solicitudes.
El índice del percentil muestral es `floor((n - 1) * p)` sobre tiempos ordenados.
Durante los primeros minutos de la repetición se ejecutó también la matriz PHPUnit y
calidad en el mismo equipo, con bases independientes. La telemetría es una observación
de este laboratorio compartido, no un benchmark de hardware dedicado.

## Regresión complementaria ejecutada

- PHP Windows 8.3.36, 8.4.27 y 8.5.12: **209 pruebas / 982 aserciones** en cada versión,
  sin fallos, errores ni saltos. Informes `.runtime/deployment-php83.log`, `php84.log`,
  `php85.log` y sus JUnit con prefijo `deployment-`.
- Grupo normativo propio: **106 / 414**, aprobado; `.runtime/deployment-own-conformance.log`.
- Larastan/PHPStan nivel 5 sin baseline: sin errores; `.runtime/deployment-analyse.log`.
- Pint: **145 archivos**, aprobado; `.runtime/deployment-pint.log`.
- E2E original con tres servidores PHP/Herd y cookie jars: SSO, ACL, Recordarme, CSRF,
  dispositivos, logout y tres entregas back-channel aprobados; `.runtime/deployment-e2e.log`.

Los cinco planes oficiales OIDF conservan sus resultados del cierre anterior; no se
atribuyen nuevas ejecuciones oficiales a esta campaña. Los ensayos de navegador/carga
de este informe complementan esa evidencia y no son certificación externa.

## Fallos detectados y corregidos

| Fallo real | Corrección y validación |
| --- | --- |
| El fixture no tenía manifiesto Composer y `view:cache` fallaba | Se incorpora un manifiesto mínimo del anfitrión; optimize y seis comprobaciones HTTP confirman cachés activas |
| Nginx arrancaba antes de resolver el upstream FPM | Readiness y dependencias explícitas de FPM/Apache antes del ingress |
| Regla de archivos ocultos bloqueaba `/.well-known/openid-configuration` | Excepción exacta para discovery, conservando el bloqueo del resto |
| La raíz FPM buscaba un índice estático | `index index.php` y front controller explícitos |
| Apache no entregaba Authorization al entorno PHP; UserInfo fallaba y el callback devolvía 400 | Propagación de Authorization mediante SetEnvIf; ambos RP pasan intercambio, UserInfo y comprobación estricta |
| CA y certificado del servidor compartían issuer/serial cero; Firefox rechazaba la navegación | Series positivas distintas y política de CA privada; los seis casos finales verifican TLS sin ignorar errores |
| El harness confundía redirect al login HTML con acceso protegido y tenía una carrera de navegación tras el 419 | Fetch real con Accept JSON para comprobar 401 y espera del fin de navegación; no se debilitan cookies ni CSRF |
| El worker del harness seguía viendo el archivo de pausa después de borrarlo desde otro proceso, por la caché de stat de PHP | `clearstatcache` antes de leer indicadores externos; se exige outbox vacía al final del propio ensayo, también en el smoke corto |

El ajuste de series se aplica también al generador del fixture OIDF, incluida detección
de pares antiguos con la misma serie para regenerarlos en la siguiente preparación.
No cambia claves OIDC/OAuth ni la implementación de autenticación. La serie de X.509 no
se debe confundir con `kid` de JWKS. PHP usa cero cuando se omite ese argumento:
[manual de openssl_csr_sign](https://www.php.net/manual/en/function.openssl-csr-sign.php).

Los intentos iniciales, diagnósticos TLS y certificados públicos de serie cero se conservaron
en `.runtime`; no se presentan como ejecuciones aprobadas. Una comparación permisiva TLS
se usó sólo para aislar el diagnóstico de Firefox y no forma parte de los resultados finales.
La primera campaña larga se detuvo al detectar que las entregas no retomaban después
de la pausa. Sus muestras `soak-30m-final-*` y `failed-recovery-note.json` se conservan
como intento fallido; los resultados aprobados deben proceder de una repetición completa.

## Resultados finales comprobados

Campaña `soak-30m-verified`, UTC **2026-11-14 23:15:01–23:45:07**:
**1805,85 s** observados, incluidos cierre de workers y comprobación final.
El exporter contrastó la huella del código, los seis diagnósticos, TLS, matriz de
navegadores, proxy, telemetría y drenaje antes de escribir
[el resumen local sin secretos](../conformance/deployment-results.json).

| Medida | Resultado |
| --- | ---: |
| Usuarios HTTP | 8, cuatro por perfil |
| Solicitudes del generador | 15 850 |
| Ciclos | 6685 |
| Media sobre duración observada | 8,78 solicitudes/s |
| Errores inesperados / usuarios fallidos | 0 / 0 |
| Rechazos durante caída OP | 240 |
| Logout verificado contra el otro RP | 80 |
| Recuperaciones de acceso | 248 |
| Recuperaciones tras retirar cookie central | 144 |
| Rechazos 503/400 de frontera en esta repetición | 0 / 0 |
| Pendientes durante pausa, MySQL / PostgreSQL | 28 / 24 |
| Pendientes al cierre, MySQL / PostgreSQL | 0 / 0 |
| Controles de navegador / proxy | 60 / 12, todos aprobados |
| Diagnósticos finales de las apps | 6 aprobados |
| Identidades en cada RP al cierre | 7, sin duplicación por reautenticaciones |

Las solicitudes rechazadas por caducidad, logout y caída programada se distinguen de
errores inesperados. No se presenta la campaña como 15 850 respuestas 200.

| Solicitud protegida | Media ms | Máximo p95 por usuario ms | Máximo observado ms |
| --- | ---: | ---: | ---: |
| FPM, RP uno | 63,21 | 106,65 | 181,92 |
| FPM, RP dos | 37,48 | 51,41 | 209,52 |
| Apache, RP uno | 75,27 | 110,67 | 196,18 |
| Apache, RP dos | 51,27 | 65,08 | 786,85 |

| Contenedor | Memoria máxima MiB | Memoria final MiB | CPU máxima Docker % |
| --- | ---: | ---: | ---: |
| PHP-FPM | 65,08 | 60,98 | 322,09 |
| Apache/PHP | 76,18 | 71,83 | 311,26 |
| Nginx ingress | 7,49 | 5,70 | 37,89 |
| MySQL | 692,00 | 688,00 | 21,98 |
| PostgreSQL | 141,40 | 130,90 | 44,69 |

Hubo **57 muestras por contenedor**, 285 en total. Docker puede indicar más de 100 %
cuando se usan varios núcleos. Estos máximos no ocurrieron necesariamente a la vez;
no se suman como un pico global ni se concluye ausencia de fugas por una sola campaña.

La repetición completa se hizo después del smoke corregido de **126,216 s** con ambas
outbox vacías. El intento largo anterior se conserva como fallido. Los contenedores,
red del laboratorio y listeners temporales del E2E quedaron detenidos al cerrar.
No hubo publicación, CI remota ni envío de evidencias a OpenID Foundation.

Artefactos privados bajo `.runtime/deployment-lab/results/`: `final-browsers.json`,
`proxy-check.json`, `images.json`, `deployment-inspection.json`,
`soak-30m-verified-soak.json`, sus ocho archivos de usuario y `soak-30m-verified-stats.jsonl`.
El resumen conserva hashes de esos originales y de la versión comprobada del script
de navegador. Capturas de las seis combinaciones permanecen privadas en ese directorio.

## Reproducción en este equipo

Requiere Docker Desktop operativo, PowerShell y PHP Herd 8.3 con OpenSSL. Los wrappers
Windows de este checkout usan su ruta instalada; las apps/SQL/navegadores se ejecutan en Linux.

```powershell
./tools/deployment-lab.ps1 -Action Build
./tools/deployment-lab.ps1 -Action Start
./tools/deployment-lab.ps1 -Action Browsers -Label final
./tools/record-deployment-images.ps1
# Ejecutar el probe en el servicio runner (incluye /results), no con docker exec al FPM.
docker compose --project-name laravel-sso-deployment --env-file .runtime/deployment-lab/laboratory.env -f conformance/deployment/compose.yml run --rm runner /app/tools/check-deployment-proxy.php
./tools/deployment-lab.ps1 -Action Soak -Minutes 30 -Label soak-30m-verified
./tools/deployment-lab.ps1 -Action Inspect
php tools/export-deployment-results.php soak-30m-verified
./tools/deployment-lab.ps1 -Action Stop
```

Start trabaja sólo con bases del laboratorio. Stop elimina sólo los contenedores de este proyecto y sus
bases tmpfs desechables; preserva evidencia, imágenes y volumen privado de aplicación.
No hay `migrate:fresh` sobre ejemplos ni copias incrementales del esquema. El exporter
rechaza campañas cortas, usuarios fallidos, matrices incompletas, TLS ignorado, outbox
sin drenar, telemetría insuficiente y huellas que no coincidan con el código actual.

Guías primarias: [Playwright Docker](https://playwright.dev/docs/docker),
[políticas Firefox](https://firefox-admin-docs.mozilla.org/reference/policies/certificates/),
[soporte de políticas en Playwright](https://github.com/microsoft/playwright/issues/35815),
[cookies SameSite](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Set-Cookie)
y [proxies de Laravel](https://laravel.com/framework/docs/requests#configuring-trusted-proxies).
