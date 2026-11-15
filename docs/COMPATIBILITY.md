# Compatibilidad ampliada: dependencias y aislamiento

El [run 43679063708, intento 1](https://github.com/engelcituk/laravel-sso-suite/actions/runs/43679063708)
pasó **14/14 trabajos, sin advertencias del runner ni fallos**, el 2026-11-15
**04:58:53–05:00:48 UTC**.
Commit probado: `4c925d377d0fd8833438726b1378c79dfb70410c`, rama
`codex/expanded-compatibility`. La actualización de este informe es posterior al commit probado.
No se integró esta rama en main ni se publicaron versiones.

## Matriz ejecutada

Nueve trabajos cruzan PHP **8.3.36, 8.4.27 y 8.5.12** con tres perfiles:

- `locked`: instalación del composer.lock conservado, con plataforma mínima 8.3.0.
- `lowest`: resolución nueva con `--prefer-lowest --prefer-stable`.
- `latest`: resolución nueva con `--prefer-stable`, dentro de los rangos declarados.

Lowest/latest configuran la plataforma Composer con el PHP real de cada trabajo antes
de resolver y ejecutan `check-platform-reqs` después. No se ignoran requisitos ni se
desactivan los controles de seguridad de Composer. El lockfile del checkout no se actualizó.
Las resoluciones alternativas sólo se generan en los workspaces desechables de CI.

| Perfil / PHP | Laravel | Passport | League OAuth2 | JWT | PHPUnit |
| --- | --- | --- | --- | --- | --- |
| locked, 8.3/8.4/8.5 | 13.42.0 | 13.10.0 | 9.4.1 | 7.2.1 | 12.5.44 |
| lowest, 8.3/8.4 | 13.40.0 | 13.7.1 | 9.2.0 | 7.0.0 | 12.5.8 |
| lowest, 8.5 | 13.40.0 | 13.7.1 | 9.3.0 | 7.0.0 | 12.5.8 |
| latest, 8.3/8.4/8.5 | 13.42.0 | 13.10.0 | 9.4.1 | 7.2.1 | 12.5.44 |

Son las versiones resueltas en esa fecha. Lowest es el mínimo conjuntamente resoluble
con las restricciones y controles activos; no significa que se hayan probado todas las
versiones históricas ni todas las combinaciones. Latest no promete versiones futuras.
Los rangos de los paquetes no necesitaron cambios en esta ejecución.

Cada uno de los nueve trabajos pasó **212 pruebas / 994 aserciones**, **106 / 414
normativas**, comprobaciones de plataforma, auditoría sin avisos conocidos, setup y E2E.
Los 106 casos normativos son un subconjunto de los 212, ejecutado también por separado;
no se suman como casos distintos. E2E verificó SSO/ACL, Recordarme, CSRF/contraseña,
dispositivos, logout, los tres doctores y tres entregas back-channel.

Pint (**140 archivos**) y Larastan nivel 5 sin baseline pasaron en los tres trabajos
`locked`, que conservan las versiones de las herramientas de calidad. Lowest/latest
validan compatibilidad de ejecución; no se aplica el estilo de un Pint antiguo como
criterio alternativo de formato. Los pasos condicionales no aplicables aparecen como
skipped en Actions; las suites PHPUnit aprobadas no tienen casos saltados.

Los otros cinco trabajos son manifiestos y cuatro perfiles SQL, con PHP 8.3.36 y el
lockfile conservado:

| Motor | Aislamiento observado | Resultado completo |
| --- | --- | --- |
| MySQL 8.4.13 | READ COMMITTED | 228 pruebas / 1070 aserciones |
| MySQL 8.4.13 | REPEATABLE READ | 228 / 1070 |
| PostgreSQL 18.7 | READ COMMITTED | 228 / 1068 |
| PostgreSQL 18.7 | REPEATABLE READ | 228 / 1068 |

Cada perfil incluye las catorce carreras, hasta ocho procesos por carrera y la regresión
completa. El padre y los hijos consultan el aislamiento real de sus conexiones; cambiar
la etiqueta del job no basta. Los dos controles adicionales de MySQL son de collations.
El trabajo de manifiestos también pasó. Los runners fueron Ubuntu 26.04.1 LTS,
agente 2.338.2 e imagen `20261111.196.1`, salvo PHP 8.3 locked/latest y PHP 8.4 lowest,
con imagen `20261111.82.6`.

## Fallos encontrados y corregidos

El [primer run 43677559713](https://github.com/engelcituk/laravel-sso-suite/actions/runs/43677559713),
commit `ec479b6`, terminó con **11 trabajos aprobados y 3 fallidos**. No se presenta como
aprobado ni se ocultaron sus logs.

1. La plataforma fija 8.3 permitía resolver `lcobucci/clock` 3.2.0 incluso en los jobs
   lowest de 8.4/8.5. `check-platform-reqs` detectó la incompatibilidad. Ahora cada
   resolución alternativa utiliza su PHP real; la comprobación de plataforma se mantiene.
2. PostgreSQL REPEATABLE READ expuso snapshots antiguos en canje, aprovisionamiento,
   revocación y Recordarme. Un SELECT FOR UPDATE no crea por sí solo una nueva versión
   de la fila. Los accesos con bloqueo versionan ahora las filas existentes de dispositivo
   y sesión antes de añadir vinculaciones, sin extender caducidades ni agregar columnas.
3. Una excepción de serialización con SQLSTATE string `40001` podía perder su código al
   pasar por un savepoint de Laravel. `Transactions::run` conserva la señal numérica a
   través de la cadena de excepciones y permite hasta diez intentos de la transacción
   completa que posee el paquete. No reintenta errores de integridad arbitrarios.
4. La revocación del token tras replay también puede sufrir serialización, incluso en
   una sentencia independiente. Ahora es transaccional, reintentable e idempotente sobre
   tokens aún activos. La respuesta OAuth sigue siendo invalid_grant.
5. Si PostgreSQL no puede ver al ganador de un conflicto de identidad dentro de un
   snapshot antiguo, el provisioner solicita reintentar la transacción exterior completa.
   Se comprueba que el índice que produjo la excepción corresponde a `(iss, sub)`:
   una restricción ajena, como email único, conserva su excepción y no se reintenta.
   Los ensayos de transacciones propiedad del llamador aplican ese contrato; no se rebajó
   el aislamiento ni se aceptan identidades duplicadas o revocaciones incompletas.

Si una aplicación envuelve estas operaciones en su propia transacción, debe reintentar
**toda esa transacción**, por ejemplo mediante `DB::transaction($operation, 10)`. Los
savepoints no refrescan un snapshot de PostgreSQL. El presupuesto es limitado: agotarlo
propaga el error. Los callbacks propios de auditoría/aprovisionamiento siguen después de
commit; los efectos externos añadidos por el anfitrión deben tolerar sus propios reintentos.

`TransactionTest` añade tres casos: rollback de filas y callbacks en intentos fallidos,
reintento tanto del paquete como del llamador, y propagación de errores no reintentables.
Las catorce carreras reales conservan sus expectativas de código único, replay, una
identidad, revocación de tokens/sesiones y no duplicación de entregas.
Un caso SQL adicional comprueba que un conflicto de email único se propaga tras un solo
intento, sin crear otra identidad ni vincular cuentas por email.

Localmente se conservaron los dos intentos PostgreSQL fallidos: el inicial, 224 pruebas
con 4 errores y 3 fallos, y el intermedio, 227 con 1 fallo. Las repeticiones completas
tras las correcciones principales pasaron PostgreSQL REPEATABLE READ **227 / 1065** y MySQL READ COMMITTED
**227 / 1067**. Pint y Larastan también pasaron. Los contenedores del laboratorio quedaron
detenidos; no se usaron las bases de Laragon. Después, el run intermedio
[43678159445](https://github.com/engelcituk/laravel-sso-suite/actions/runs/43678159445)
pasó 14/14 con 227 pruebas SQL. La repetición final indicada al inicio añade el caso de
restricción ajena y confirma 228 pruebas por perfil; no se atribuye ese caso a los runs anteriores.

## Evidencia y reproducción

[Resumen estructurado](../conformance/compatibility-results.json): IDs de los catorce
jobs, versiones, aislamientos observados, conteos, huellas de los logs, intentos fallidos
y metadatos de **13 artifacts**. CI subió nueve artifacts PHP con environment.json,
installed.json, composer.lock y JUnit, y cuatro SQL con entorno/JUnit. Retención: siete
días; los del run documentado vencen el 2026-11-22. Sus digests son los de las archives
remotas; no se afirma haber descargado sus ZIP. No incluyen claves, .env, cookies ni
los resultados crudos de procesos concurrentes.

Logs privados finales en `.runtime/ci-remote/run-43679063708/`, repetición intermedia
en `run-43678159445/`, fallos anteriores en
`run-43677559713/` y resultados locales `compatibility-*` bajo
`.runtime/database-lab/results/`. Las huellas de logs corresponden a los archivos
decodificados descargados, no a las archives de artifacts.

```powershell
./tools/database-lab.ps1 -Action Start
./tools/database-lab.ps1 -Action Test -Engine mysql -Isolation 'READ COMMITTED' -Label mysql-rc
./tools/database-lab.ps1 -Action Test -Engine pgsql -Isolation 'REPEATABLE READ' -Label pg-rr
./tools/database-lab.ps1 -Action Stop
```

Omitir Isolation conserva el perfil histórico de cada motor. CI ejecuta los cuatro
perfiles explícitos y puede repetirse desde el run. Las resoluciones Composer deben
realizarse en checkouts desechables; no actualizar vendor/lock de una instalación real
para reproducir esta matriz.

## Límites y estado

La fase solicitada queda verificada. La matriz SQL usa PHP 8.3 y locked; no es el producto
completo de nueve resoluciones PHP por cuatro perfiles SQL. No se repitieron el harness
oficial OIDF, los navegadores ni la carga de 30 minutos: sus informes previos conservan
su alcance y código originales. No hay certificación ni auditoría externa nueva.
Servidor y cliente conservan una migración inicial cada uno; contracts no tiene esquema.
No hubo migraciones incrementales, cambios del lockfile principal ni publicación de versiones.

Fuentes primarias: [resolución de Composer](https://getcomposer.org/doc/03-cli.md#update-u-upgrade),
[aislamiento PostgreSQL](https://www.postgresql.org/docs/18/transaction-iso.html#XACT-REPEATABLE-READ)
y conectores Laravel [MySQL](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Connectors/MySqlConnector.php)
y [PostgreSQL](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Connectors/PostgresConnector.php).
