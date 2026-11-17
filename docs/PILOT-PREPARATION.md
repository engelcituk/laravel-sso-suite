# Preparación verificable del piloto

16 de noviembre de 2026, Cancún (ejecuciones UTC del 17 de noviembre). Validación local
del workspace sobre `98b6e47`; las modificaciones de esta fase se identifican mediante
huellas de fuentes y dependencias. No se atribuyen a una nueva CI remota ni a un piloto
externo. No se publicaron versiones, tags, releases, imágenes ni evidencias externas.

**Estado: preparación local verificada.** Los exportadores aprobaron los cinco planes
OIDC, los 30 casos de modos, los 60 controles cross-site, los dos ensayos de adaptación
y la campaña completa de 30 minutos con huellas coincidentes. El piloto externo sigue pendiente.

[Resumen consolidado sin secretos](../conformance/pilot-preparation-results.json),
[despliegues y carga actuales](../conformance/pilot-deployment-results.json).
Los diez archivos originales protegidos de ejemplos (bases y secretos) conservaron
exactamente su contenido. No se modificaron los paquetes ni sus migraciones.
Al cierre se detuvieron ambos proyectos Docker de laboratorio; se conservaron volúmenes
privados e informes. No quedaron contenedores de estas campañas ejecutándose.

## OIDC oficial repetido

Directorio privado: `.runtime/conformance/official-pilot-verified-20261117-033225/`.
Inicio UTC `2026-11-17T03:32:25+00:00`, fin `03:35:24+00:00` (22:32:25–22:35:24 del
16 de noviembre en Cancún). Suite oficial sin modificar, revisión
`daca6ad2068468d42557ea1152f899cc3ac01a10` y JAR
`8b77b18cef94f0aa21505f3d5ecdb6cb5ec7f90c37f61988bc00068a406eba0f`.

| Plan | ID oficial local | PASSED | REVIEW | SKIPPED | FAILED/WARNING |
| --- | --- | ---: | ---: | ---: | ---: |
| Discovery | `9BM848ugSPdYS` | 1 | 0 | 0 | 0 |
| Basic query | `j7IwLBepqCus2` | 26 | 4 | 5 | 0 |
| Basic form_post | `JbeCrjGCj5waf` | 26 | 4 | 5 | 0 |
| RP-Initiated Logout | `gnDsQumqpnlHZ` | 3 | 8 | 0 | 0 |
| Back-Channel Logout | `8HcjV7UNUXpIh` | 2 | 0 | 0 | 0 |
| Total: 84 FINISHED | | 58 | 16 | 10 | 0 |

Las 16 revisiones DOM y los 10 saltos de capacidades se verificaron de nuevo sobre
los exports de esta ejecución. REVIEW y SKIPPED mantienen su estado oficial: no se
convierten en PASSED. Se conserva la excepción sin PKCE únicamente en los dos clientes
confidenciales del harness; el cliente Laravel utiliza S256. No se solicitó certificación.
Transporte adicional: 80/80 solicitudes HTTPS, cuatro simultáneas, máximo 58,18 ms.

[Resumen oficial](../conformance/oidf-results.json),
[revisión de evidencias](../conformance/oidf-review.json).
El primer ensayo de esta fase, `official-pilot-preparation-20261117-032443`, también
terminó 58/16/10 sin fallos ni advertencias; se conserva y se repitió tras corregir el
arranque de la ACL en los ejemplos.

## Ambos modos con cachés y reinicios

Se ejecutaron cinco fases × tres motores × dos despliegues: **30 casos / 174 controles**,
todos aprobados. Los despliegues son Nginx/FPM/PHP 8.3/MySQL con sesiones en archivos y
Apache/PHP 8.4/PostgreSQL con sesiones en DB. Cada perfil contiene un OP y dos RP con
bases, claves y cookies independientes; todos usan HTTPS estricto y OPcache.

| Fase | Casos | Controles | Comportamiento comprobado |
| --- | ---: | ---: | --- |
| Auth local inicial | 6 | 42 | OP devuelve 503; login local funciona; SSO devuelve 404; CSRF y ACL independientes |
| Activar SSO | 6 | 30 | Sesiones locales previas rechazadas; login nativo redirigido, POST con CSRF válido devuelve 403; un login central para dos RP |
| Caída del OP con SSO | 6 | 18 | Ambos RP rechazan sus sesiones con 401; no habilitan login local automáticamente |
| Recuperación del OP | 6 | 36 | Reautenticación OIDC sin reenviar contraseña central; mismos usuarios vinculados y ACL |
| Volver a Auth local | 6 | 48 | Sesiones SSO rechazadas; exige login local nuevo y funciona con OP caído |

Antes de cada cambio de flag se reconstruyeron `config:cache` y `route:cache` y se
reiniciaron FPM/Apache. Se comprobó el modo efectivo, ambas cachés y OPcache por HTTP
en los cuatro RP. Los contextos de navegador conservaron sus cookies entre fases para
probar sesiones existentes. Motores: Chromium 159.0.8154.6, Firefox 159.0 y WebKit 27.2,
Playwright 1.65.1; `ignoreHTTPSErrors=false`.

Tres cuentas sintéticas por RP se crearon y vincularon explícitamente antes de tomar la
línea base. La comparación posterior de las cuatro bases comprobó esquema y todas las
filas/atributos de `users`, passwords, `remember_token` y ACL sin cambios durante la
matriz. Una discrepancia deliberada en la evidencia, sin modificar datos, hizo fallar el
validador con salida 1; al restaurar la evidencia, devolvió 0.

Los informes originales son `pilot-modes-final-{fase}.json` y
`modes-preservation-{perfil}-{rp}.json` en `.runtime/deployment-lab/results/`.
Los archivos `*-private-state.json` contienen cookies de laboratorio y permanecen ignorados.

## Navegadores cross-site y operación

Se repitieron los **60 controles cross-site**, seis combinaciones motor/despliegue:
SSO entre tres sitios registrables, una entrada de credenciales, ACL sin privilegios,
cookies host-only/Secure/HttpOnly/Lax, iframe sin autenticación, CSRF 419, Recordarme,
contraseña reciente/dispositivos y logout global con 401 en el segundo RP.
Pasaron además los seis doctores con aplicaciones optimizadas y **12 controles de proxy/Host**.

La campaña final `pilot-soak-30m-verified` solicitó 1800 segundos y terminó en
**1806,038 segundos**, con ocho usuarios HTTP, cuatro por despliegue. Inicio UTC
`2026-11-17T03:41:53+00:00`, fin `04:11:59+00:00` (22:41:53–23:11:59 del 16 de noviembre
en Cancún). Los ocho procesos terminaron correctamente.

| Resultado de la campaña | Valor observado |
| --- | ---: |
| Solicitudes HTTP externas | 15 832 |
| Ciclos | 6676 |
| Errores inesperados / workers fallidos | 0 / 0 |
| Rechazos durante caída del OP | 232 |
| Comprobaciones de rechazo tras logout | 80 |
| Recuperaciones de sesión / Recordarme | 248 / 144 |
| Transiciones 503 / callback 400 | 0 / 0 |
| Entregas pendientes durante pausa, MySQL / PostgreSQL | 28 / 24 |
| Pendientes al cierre, MySQL / PostgreSQL | 0 / 0 |
| Entregas completadas al cierre, MySQL / PostgreSQL | 206 / 202 |

La caída del OP FPM comenzó a 300,176 s y terminó a 360,086 s; la pausa de workers fue
de 900,163 a 1020,132 s. Ambas colas ya estaban vacías en la lectura posterior a la
reanudación y volvieron a comprobarse vacías al cierre. Se obtuvieron 57 muestras por
cada uno de los cinco contenedores, desde 03:41:54 hasta 04:11:47 UTC.
Los usuarios de carga HTTP no son procesos de navegador. No es una prueba de capacidad máxima,
carga distribuida ni ejecución multijornada.

## Ensayo de instalaciones existentes

Se reprodujo el esquema cliente anterior a Auth opcional (`848b35c`) en un espacio de
tablas desechable por motor. La migración inicial figuraba ya ejecutada: `migrate`
no volvió a aplicarla y no creó `sso_identities` por sí solo.

La adaptación explícita creó sólo `sso_identities`, añadió el propietario en
`sso_local_sessions`, revocó sesiones antiguas para exigir autenticación nueva y conservó
los recibos de logout. La vinculación explícita fue idempotente. En ambos motores se
compararon esquema, atributos del usuario, hash válido y ACL: permanecieron iguales.
Los espacios temporales se retiraron al aprobar el ensayo.

El primer intento encontró colisiones de nombres de índices/constraints con las tablas
del fixture: se activó `prefix_indexes` para aislar también esos nombres y se repitió
correctamente. No se resolvió borrando tablas de la aplicación.

`tools/deployment-upgrade.php` es exclusivamente un ensayo protegido por entorno/ruta;
no es un actualizador de producción. Una instalación real requiere su propio respaldo,
revisión de esquema y comprobación de `iss/sub`. No se ejecutó `migrate:fresh` ni se
adaptaron tus bases existentes. Sigue habiendo una migración servidor, una cliente y
ninguna en contracts.

## Correcciones y calidad

- Los Gates/Policies de los ejemplos estaban registrados en el archivo de rutas y no
  se cargaban con `route:cache`. Se trasladaron a un archivo de autorización cargado
  al arrancar los RP. La primera matriz falló al comprobar un permiso local legítimo;
  las dos matrices completas posteriores verificaron permiso en A y denegación en B.
- El laboratorio incorporó la fábrica explícita del anfitrión exigida por el cliente
  actualizado y la redirección de invitados según el modo. No cambió código de los paquetes.
- Se actualizó el selector del botón de logout del harness al formulario SSO vigente.
  La huella de despliegue incluye ahora las fuentes compartidas de los ejemplos.
- PHPUnit PHP 8.3.36: **230 pruebas / 1076 aserciones**, aprobado. Larastan nivel 5 sin
  baseline: sin errores. Pint de los archivos modificados: aprobado.
- Pint global del checkout Windows señaló **37 archivos preexistentes sin cambios en
  esta fase**. Esos caminos también fallan en una copia del HEAD original; el archivo
  exportado tiene además diferencias de finales de línea. No se afirma Pint global verde
  para este workspace ni se reescriben paquetes/migraciones para ocultar el resultado.

Los logs y JUnit de calidad están en `.runtime/pilot-preparation-20261117/`. Se conservan
el primer fallo de modos y una campaña corta interrumpida voluntariamente con salida 137
para reforzar el validador; esa campaña no cuenta como carga de 30 minutos aprobada.

## Reproducción y cierre

```powershell
.\tools\oidf-docker.ps1 -Action Start
.\tools\oidf-docker.ps1 -Action Run -Mode compatibility -Label pilot-verified
# Localiza el nuevo summary.json y revisa/exporta con oidf-review.php y oidf-summary.php.
.\tools\deployment-lab.ps1 -Action Build
.\tools\deployment-lab.ps1 -Action Start
$deploymentCompose = @('compose', '--project-name', 'laravel-sso-deployment',
    '--env-file', '.runtime/deployment-lab/laboratory.env', '-f', 'conformance/deployment/compose.yml')
docker @deploymentCompose run --rm runner /app/tools/deployment-upgrade.php fpm
docker @deploymentCompose run --rm runner /app/tools/deployment-upgrade.php apache
.\tools\deployment-lab.ps1 -Action Modes -Label pilot-modes-final
.\tools\deployment-lab.ps1 -Action Browsers -Label pilot-final
.\tools\record-deployment-images.ps1
docker @deploymentCompose run --rm runner /app/tools/check-deployment-proxy.php
.\tools\deployment-lab.ps1 -Action Soak -Minutes 30 -Label pilot-soak-30m-verified
.\tools\deployment-lab.ps1 -Action Inspect
php tools/export-deployment-results.php pilot-soak-30m-verified pilot-final pilot-deployment-results
.\tools\deployment-lab.ps1 -Action Stop
.\tools\oidf-docker.ps1 -Action Stop
```

Para cerrar el resumen de esta fase, `tools/pilot-summary.php` requiere además los registros
de calidad, los controles negativos, las huellas de fuentes/inventario de `pilot-fingerprint.php`
en los tres entornos y las huellas antes/después de los archivos protegidos de ejemplos.
Los exportadores rechazan campañas
incompletas o fuentes distintas y seleccionan campos sin secretos. No se publican los
JSON/HTML/ZIP originales, cookies, configuraciones ni credenciales de los fixtures.

La siguiente fase sigue siendo un **piloto externo con destino y operadores concretos**,
revisión independiente y criterios acordados de aceptación/recuperación. Siguen fuera
del alcance navegadores comerciales, Safari en macOS/iOS, móviles físicos, carga
distribuida/multijornada y certificación externa. Esta preparación local no cierra esas tareas.
