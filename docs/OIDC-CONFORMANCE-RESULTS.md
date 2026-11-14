# Resultados OIDC y revisión de observaciones

2026-11-14. Se repitieron los cinco planes al incorporar Recordarme central y dispositivos.
Se mantienen resueltas las 6 advertencias anteriores; se auditaron de nuevo las 16 revisiones
y los 10 saltos. **Resultado final: 84 módulos FINISHED, 58 PASSED, 0 FAILED, 0 WARNING,
16 REVIEW y 10 SKIPPED.** Ver DATABASE-CONCURRENCY.md y REMEMBER-DEVICES.md para las otras matrices.
No se publicó nada. Estos resultados locales no son una certificación externa.

## Evaluación oficial final

Fuente oficial sin modificar: `daca6ad2068468d42557ea1152f899cc3ac01a10`.
Inicio UTC: `2026-11-14T21:04:29+00:00`. Fin UTC: `2026-11-14T21:07:21+00:00`.

| Plan | ID oficial local | Total | PASS | WARN | REVIEW | SKIP | FAIL |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Discovery | `lR4QTM1e9VBL3` | 1 | 1 | 0 | 0 | 0 | 0 |
| Basic query | `BlSoqFOQcv6NI` | 35 | 26 | 0 | 4 | 5 | 0 |
| Basic form_post | `YXBRLbrX7Su7S` | 35 | 26 | 0 | 4 | 5 | 0 |
| RP-Initiated Logout | `sNVg69uIrjOPq` | 11 | 3 | 0 | 8 | 0 | 0 |
| Back-Channel Logout | `RpwcDiMOsao77` | 2 | 2 | 0 | 0 | 0 | 0 |
| Total | | 84 | 58 | 0 | 16 | 10 | 0 |

Se ejecutaron todos los módulos que la suite seleccionó para discovery/static_client,
Code, query/form_post y los métodos Basic/secret_post fijados por los planes. No se
cambiaron módulos, plazos ni criterios oficiales. Se usó la excepción autorizada sin PKCE
sólo para los dos clientes confidenciales de laboratorio. S256 sigue siendo obligatorio
por defecto y para clientes públicos; el RP Laravel siempre lo utiliza.

## Qué significaban las seis advertencias y qué cambió

Cada una de estas tres condiciones aparecía en Basic y en Form Post:

| Advertencia anterior | Corrección verificada |
| --- | --- |
| scope-profile | El OP sólo exponía name. Ahora mapea los atributos normales de profile desde columnas o JSON casteado, con tipos válidos y sin cargar relaciones. La identidad sintética tiene datos de prueba explícitos para comprobar todos los campos. Los datos ausentes de usuarios reales se siguen omitiendo. |
| claims-essential | No se implementaba claims. Ahora se admiten selecciones userinfo/id_token, essential y value/values, se muestran en consentimiento y se persisten por código/token. La selección no puede ampliarse desde consentimiento o token. |
| ensure-request-with-acr-values-succeeds | No se anunciaba el contexto de autenticación. Ahora se anuncia y devuelve acr=0: no afirma MFA ni un nivel superior de aseguramiento. Un acr esencial no disponible produce rechazo; una preferencia voluntaria no hace que se copie una clase falsa. |

Las seis condiciones terminaron **PASSED**. No se ocultaron avisos ni se alteró la suite.
La prueba de perfil usa una cuenta ficticia con atributos almacenados: el paquete no
inventa nombres, género, fechas o enlaces para usuarios reales. Evaluar una cuenta sin
esos atributos puede producir de nuevo el aviso de la suite sobre profile incompleto.

La revisión añadió además regresiones para sub no coincidente, atributos privados,
claims desconocidos con valores complejos, límites/tipos JSON y selección por destino.
Los valores false, 0 o inválidos de verificación no se convierten en email_verified=true.
No se concede otra identidad ni se liberan passwords, roles o columnas arbitrarias.

## Las dieciséis revisiones

REVIEW significa que el módulo pide evidencia de una pantalla o decisión del usuario.
No equivale a un fallo. Se verificaron de nuevo las capturas DOM reales de HtmlUnit y sus
trazas originales con [la auditoría reproducible](../tools/oidf-review.php):

| Evidencia | Cantidad | Comprobación local |
| --- | ---: | --- |
| prompt=login y max_age=1 | 4 | formulario POST al origen del OP, email/password, CSRF, contraseña sin rellenar y dos envíos de login |
| redirect no registrado y redirect de Request Object | 4 | invalid_request explícito, HTTP 400, sin formulario ni script que redirija |
| logout inválido | 5 | rechazo HTTP 400 del hint o destino inválido, sin redirección |
| logout sin parámetros/destino o sólo state | 3 | confirmación POST con CSRF y transacción; acción realizada y página de cierre satisfactorio |

**16/16 evidencias verificadas localmente.** No quedan capturas sin revisar en esta auditoría.
Se rechazan categorías desconocidas o una comprobación semántica fallida. El informe sin
secretos contiene checks y SHA256 de los ZIP originales: [oidf-review.json](../conformance/oidf-review.json).
El estado oficial permanece REVIEW; no se modifica para simular PASSED o una aprobación
externa. Son instantáneas DOM de la función oficial, no fotografías de Chrome.

## Los diez saltos

SKIPPED significa que la prueba no se aplica a una capacidad que el OP no anuncia.
Se contrastó cada salto con el discovery guardado en su propio export:

| Capacidad opcional | Módulos saltados | Comprobación |
| --- | ---: | --- |
| address | 2 | no anunciado; su scope se rechaza |
| phone | 2 | no anunciado; su scope se rechaza |
| combinación de todos los scopes | 2 | requiere address/phone, que no se anuncian |
| Request Objects | 2 | request_parameter_supported=false y request_not_supported; request_uri nunca se descarga |
| refresh_token | 2 | grant no anunciado y token endpoint rechaza el grant |

**10/10 saltos justificados.** Se añadieron pruebas negativas de capacidades para comprobar
que estos límites se cumplen, incluido offline_access. No son módulos olvidados ni aprobados:
esas funciones opcionales siguen fuera del perfil deliberadamente.

## Incidencias del laboratorio resueltas

Las repeticiones con el OP de Windows detectaron timeouts intermitentes de HtmlUnit.
El laboratorio ahora ejecuta el OP y el worker PHP junto a la suite en la red Docker,
con cuatro procesos de atención y una copia de código/vendor en almacenamiento Linux.
Ya no depende del salto Docker→PHP de Windows ni de recorrer NTFS por cada petición.
Se excluyeron las junctions recursivas de Testbench y se reconstruyeron los enlaces de paquetes.
Se verificó que la huella del código de aplicación/configuración y del inventario Composer
coincide entre el workspace y el OP de Linux; el registro está en runtime-check.json.

El primer ensayo Linux detectó permisos de claves heredados de Windows. Se corrigieron
a 0600; no se desactivó la validación de permisos de Passport. Se conservan los intentos
fallidos. La ejecución final completa no tuvo fallos ni advertencias. La comprobación
adicional de transporte del 2026-11-13 hizo **80 solicitudes HTTPS, 4 simultáneas, 80 correctas, máximo
25,3 ms**, con plazo propio de 5 segundos. No es un módulo de conformidad ni una prueba de
carga distribuida. El límite del navegador oficial no se aumentó.

También se verificó un nuevo ciclo de arranque/parada del laboratorio: UserInfo sin
credenciales devuelve 401 y las claves conservan permisos 0600. Los contenedores quedaron detenidos.

## Calidad local ejecutada

| Comprobación | Resultado |
| --- | --- |
| PHPUnit en PHP 8.3.36 / 8.4.27 / 8.5.12 | 184 tests / 810 aserciones en cada versión; aprobado |
| Matriz completa MySQL 8.4.13 / PostgreSQL 18.7 | 199 tests por motor; 883 / 881 aserciones; sin fallos ni saltos |
| Grupo normativo propio | 106 tests / 414 aserciones; sin saltos; aprobado |
| PHPStan/Larastan nivel 5, sin baseline | aprobado |
| Pint | aprobado |
| Composer validate/audit del lockfile conservado | aprobado / sin avisos conocidos en la consulta del 2026-11-13; dependencias sin cambios |
| E2E tres aplicaciones en Windows | SSO, ACL, recuperación/rotación, dispositivos, CSRF, logout y tres entregas back-channel aprobados |

OP del ensayo oficial: Linux PHP 8.3.36, SQLite 3.40.1; Laravel 13.42.0,
Passport 13.10.0, League OAuth2 Server 9.4.1 y firebase/php-jwt 7.2.1.
Suite: Java Temurin 21.0.13+9, MongoDB 6.0.13 y nginx 1.27.3.
Host: Windows, Docker Desktop 4.99.0, Engine 29.9.3, Compose v5.7.0 y WSL 3.0.5.
Las pruebas PHP de Windows usan Herd y SQLite 3.53.4 en PHP 8.3. La matriz MySQL/PostgreSQL
se ejecutó por separado en Linux; ver DATABASE-CONCURRENCY.md. No se afirma una campaña
de carga prolongada ni una auditoría externa de seguridad.

## Evidencias y reproducción

- [Resumen oficial sin secretos](../conformance/oidf-results.json), [auditoría de revisiones/saltos](../conformance/oidf-review.json).
- Originales JSON/HTML y diez ZIP: `.runtime/conformance/official-remembered-devices-20261114-210428/`.
- Regresión tras concurrencia y su informe previo: `.runtime/conformance/official-database-regression-20261114-195830/`.
- Regresión anterior, huellas e informe previo: `.runtime/conformance/official-linux-final-20261113-211549/`.
- Verificación de transporte: `.runtime/conformance/transport-check.json`.
- Comparación anterior de 6 advertencias: `.runtime/conformance/official-complete-20261113-200426/`.
- Intentos intermedios, errores de red/permisos y ensayo estricto anterior: directorios official-*; se conservaron.
- PHPUnit propio: junit.xml y result.json bajo .runtime/conformance; no son informes OIDF.
- Comandos: [OIDF-WINDOWS-SETUP](OIDF-WINDOWS-SETUP.md), [alcance](OIDC-CONFORMANCE.md).

Los originales contienen credenciales de laboratorio y permanecen ignorados por Git.
No se usó la instancia pública, no se enviaron evidencias a OpenID Foundation y no se
publicaron código, paquetes, imágenes Docker ni resultados.
