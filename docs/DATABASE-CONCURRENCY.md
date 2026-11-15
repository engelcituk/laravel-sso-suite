# Concurrencia y limpieza de estado

Evaluación local del 2026-11-14. Los dos motores se ejecutaron en Docker Linux, en una
red interna sin puertos de base de datos publicados. No se usaron las bases de Laragon.
Las imágenes y sus digests, versiones, aislamiento, huella de código y conteos están en
[el informe verificable](../conformance/database-results.json).

## Alcance ejecutado

| Entorno | Aislamiento | Suite completa |
| --- | --- | --- |
| MySQL 8.4.13 / InnoDB | REPEATABLE READ | 224 pruebas, 1055 aserciones, sin fallos ni saltos |
| PostgreSQL 18.7 | READ COMMITTED | 224 pruebas, 1053 aserciones, sin fallos ni saltos |
| SQLite / Windows PHP 8.3.36, 8.4.27 y 8.5.12 | pruebas secuenciales | 209 pruebas, 982 aserciones en cada versión |

La diferencia de dos aserciones comprueba las collations de MySQL. El runner Linux usa
PHP 8.3.36, Laravel 13.42.0, Passport 13.10.0, League 9.4.1 y PHPUnit 12.5.44.
Ejecución completa exportada tras auditoría/operaciones: `audit-final-20261114-215054`,
con sus JUnit y versiones bajo `.runtime/database-lab/results/`. La anterior,
`remember-final-20261114-210314`, se conserva. En esa fase pasaron tres rondas adicionales por motor:
`remember-stable-1`, `remember-stable-2` y `remember-stable-3`, cada una con
**14 pruebas / 68 aserciones** sin fallos. La matriz anterior y sus logs también se conservan.
Se ejecuta toda la regresión, no sólo pruebas con SQL simulado. Cada prueba parte de un
esquema nuevo en `sso_lab_test`; la creación y reversión usan las migraciones únicas de
los paquetes y las originales de Passport.

Los diez casos de `tests/Database/ConcurrencyTest.php` utilizan procesos `pcntl_fork`
con conexiones PDO independientes y barreras entre operaciones. Hasta ocho procesos
coinciden en cada carrera:

1. Ocho canjes del mismo código: una respuesta 200, siete 400; el replay revoca el token
   comprometido y no deja tokens de los intentos revertidos.
2. Ocho altas de la misma identidad dentro de transacciones: un usuario por `(iss, sub)`,
   sin unir por email ni conceder atributos administrativos.
3. Revocación confirmada mientras otro proceso ya insertó un token sin hacer commit:
   el canje termina con 400 y se revierte completamente.
4. Ocho revocaciones simultáneas: una entrega por `(sid, client_id)`, tokens revocados.
5. Revocación desde una transacción con snapshot anterior: incluye el token que otro
   proceso acaba de confirmar y crea su entrega de logout.
6. Ocho workers compiten por una entrega: una reserva y un intento HTTP simulado.
7. Worker pausado cuya reserva se recupera: su resultado tardío no pisa al nuevo dueño;
   el siguiente reintento puede completar la entrega.
8. Ocho recibos del mismo Logout Token: todos reciben 200, un recibo durable y ninguna
   revocación de otro dispositivo.
9. Ocho workers vacían dieciséis entregas: dieciséis intentos, sin duplicados durante
   reservas vigentes, y todas las entregas confirmadas.
10. Ocho procesos de limpieza: cada fila vencida se cuenta una vez y se conservan las
    sesiones con entregas pendientes.

`RememberConcurrencyTest` añade cuatro casos: un consumidor por credencial con revocación
ante replay, revocación antes de rotar, revocación de una recuperación recién confirmada
desde un snapshot anterior y ocho dispositivos independientes. Ver [política y evidencia](REMEMBER-DEVICES.md).

Las respuestas HTTP externas de estos casos se simulan para controlar las pausas y los
errores 503; las transacciones y bloqueos son reales. El E2E de tres aplicaciones y la
regresión OIDF utilizan HTTP real y se registran por separado. Esto no mide rendimiento
de producción ni garantiza entrega exactamente una vez ante pérdidas de confirmación.

## Fallos encontrados y corregidos

Los registros anteriores se conservan en `.runtime/database-lab/results/baseline-*` y
`baseline-races-*`. La segunda referencia corrige una lectura del estado HTTP en el
arnés inicial y reproduce los defectos del código original:

- El consumidor que perdía la carrera de un código no comunicaba replay después del
  rollback. Ahora también revoca los tokens emitidos por el código consumido.
- La lectura de sesión durante un canje podía usar un snapshot anterior a la revocación
  en MySQL. Canje, revocación y borrado final toman primero el bloqueo de la sesión.
- La revocación usa una lectura actual con bloqueo de las vinculaciones; no pierde
  tokens confirmados mientras su transacción conservaba un snapshot anterior.
- `createOrFirst` podía no encontrar al ganador de una inserción concurrente en MySQL.
  El conflicto único se recupera mediante lectura actual. Si el llamador abre una
  transacción, debe reintentar **la transacción completa** ante deadlock, por ejemplo
  `DB::transaction(fn () => $users->provision($identity), 5)`. No se reanuda una transacción
  abortada dentro del provisionador. `IdentityProvisioned` se emite después del commit;
  sus callbacks se descartan al hacer rollback.
- Los resultados de workers se actualizan sólo si coincide la generación de reserva
  (`attempts`). Se usa `FOR UPDATE SKIP LOCKED` en ambos motores; SQLite conserva su
  alternativa. La reserva cubre al menos 60 segundos y el timeout HTTP más 5 segundos.
- PostgreSQL rechaza UUID mal formados. Los endpoints validan el identificador antes de
  consultar columnas UUID; se normalizaron fixtures que antes sólo funcionaban en SQLite.
- Un GET con Content-Type JSON ya no pierde sus parámetros de query. También se conserva
  el rechazo de parámetros duplicados.
- El índice local compara `issuer`, `subject` y `sid` con sensibilidad a mayúsculas en
  MySQL, incluso cuando la conexión usa `utf8mb4_unicode_ci`. La tabla de usuarios del
  ejemplo y la guía aplican el mismo requisito al índice único de identidad.

El arnés conserva las evidencias de intentos HTTP en archivos independientes: un ensayo
con append compartido en el bind mount de Windows perdió un registro. No se relajó el
conteo de entregas ni el requisito de ausencia de duplicados.

## Retención implementada

Los comandos sólo limpian estado SSO; no eliminan usuarios, clientes, credenciales,
permisos ni grants Passport ajenos. Usan lotes y bloqueos, con reintentos acotados:

| Comando / entidad | Regla |
| --- | --- |
| `sso:prune-server` / códigos y contextos | vencimiento + `state_retention`; mínimo 1 hora, por defecto 1 día; sólo códigos con contexto SSO |
| Entregas completadas | `delivered_at` anterior al plazo de retención; las pendientes nunca se eliminan por edad |
| Sesiones globales y vinculaciones | expiración absoluta + `logout_hint_retention` + `state_retention`; sin entregas pendientes/retenidas ni access tokens todavía recientes |
| Access tokens propios | se eliminan junto con la sesión elegible, sólo después de vencer y cumplir la retención; no se tocan tokens del anfitrión |
| `sso:prune-client` / índices locales | revocados o con `expires_at` vencido; la ausencia del índice deniega acceso |
| Recibos de logout | `receipt_retention`, por defecto 1 día; nunca menos que `max_token_age + 2 * clock_skew + 1` |

El callback fija `expires_at` al menor plazo entre `local_session_ttl` (300 segundos por
defecto) y `expires_in` del access token. El middleware aplica ese límite incluso con
caché central reciente. Los índices introducidos manualmente sin expiración se conservan
hasta su revocación; no se les inventa una fecha. No se usa el ID Token como sesión durable.

En el OP, programa `sso:prune-server`; en cada RP, `sso:prune-client`, por ejemplo cada
hora con `withoutOverlapping()`. `--limit=1000` limita el lote por entidad; se puede reducir
durante mantenimiento. Las entregas pendientes bloquean sólo la eliminación de sus
sesiones, sin impedir que avance la limpieza de otras. El scheduler no queda instalado
automáticamente. Monitoriza el outbox y repara los destinos que fallen; no se descartan.

## Reproducción y evidencia

```powershell
.\tools\database-lab.ps1 -Action Start
.\tools\database-lab.ps1 -Action Test -Label evaluation
# Repetición dirigida, manteniendo ambos motores:
.\tools\database-lab.ps1 -Action Test -Label races -Filter ConcurrencyTest
php tools/database-summary.php evaluation-YYYYMMDD-HHMMSS
.\tools\database-lab.ps1 -Action Stop
```

`Start` construye una imagen local y copia el código/vendor a almacenamiento Linux.
Las contraseñas aleatorias, backups, logs, barreras y JUnit quedan en `.runtime`.
`Stop` retira sólo el proyecto de laboratorio; las bases son desechables y se pierden al
retirar los contenedores, mientras los informes del host permanecen. El exportador exige
dos suites completas sin fallos/saltos, los diez casos y huellas de código coincidentes.
No exporta JWT, HTML, contraseñas ni secretos de clientes.

GitHub Actions tiene una matriz adicional para estos motores; **no se ejecutó remotamente**.
Los tests fuerzan carreras concretas a los aislamientos indicados; no acreditan otros
aislamientos/versiones, clústeres ni pérdida de red real. Una evaluación posterior
añadió [30 min de carga HTTP y navegadores cross-site](DEPLOYMENT-VALIDATION.md) en
MySQL/PostgreSQL con Nginx/FPM y Apache, separada de estas carreras.
La entrega back-channel sigue siendo al menos una vez y el RP debe reconocer duplicados.
Recordarme, dispositivos y auditoría/diagnóstico ya están implementados y evaluados.
El roadmap distingue esa validación local del piloto y revisión externos.

Las decisiones de bloqueo se contrastaron con la documentación primaria de
[MySQL 8.4](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html),
[su aislamiento](https://dev.mysql.com/doc/refman/8.4/en/innodb-transaction-isolation-levels.html)
y [PostgreSQL](https://www.postgresql.org/docs/18/explicit-locking.html).
