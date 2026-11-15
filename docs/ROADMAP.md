# Roadmap y evidencia

Actualizado 2026-11-15 UTC. Primera vertical integrada **experimental**, sin publicación de versiones,
certificación ni recomendación de producción. Una vertical no cierra toda su fase.

| Fase | Estado real | Evidencia / cierre pendiente |
| --- | --- | --- |
| 0 análisis | base completada | versiones/APIs inspeccionadas, arquitectura, decisiones, modelo y matriz |
| 1 fundamento | base local y CI remota verificadas | checkout integrado en main; matriz ampliada 14/14 en rama codex: PHP 8.3/8.4/8.5 con locked/lowest/latest y cuatro perfiles SQL, cero advertencias del runner |
| 2 servidor | perfil Code evaluado localmente | cinco planes oficiales en Docker; PKCE por defecto, excepción confidencial explícita, replay y canal de errores corregidos; ver resultados reales |
| 3 cliente | validación OIDC ampliada | iss, at_hash, aud/azp, max_age, Logout Tokens y UserInfo con sub coincidente; falta evaluación externa |
| 4 identidad local | concurrencia local y remota verificadas | unicidad/commit, snapshots y reintentos corregidos; MySQL/PostgreSQL en READ COMMITTED y REPEATABLE READ, ACL independiente |
| 5 sesiones | funcionalidades locales verificadas | Recordarme central con rotación/replay, dispositivos, revocación, outbox y retención probados; validación operativa externa pendiente |
| 6 instalación | despliegues y operación local verificados | Nginx/FPM/MySQL y Apache/PHP/PostgreSQL optimizados, 60 controles de navegador y campaña de 30 min; otros entornos/piloto externo fuera de alcance |
| 7 publicación | pendiente | calidad local y matriz remota aprobadas; faltan auditoría/certificación externa y autorización para publicar versiones |

## Resultados ejecutados

- Compatibilidad ampliada: **14/14 trabajos aprobados** en `4c925d3`, run `43679063708`,
  rama `codex/expanded-compatibility`, UTC 2026-11-15 04:58:53–05:00:48.
  Nueve combinaciones PHP/dependencias: **212 / 994** generales y **106 / 414** normativos
  cada una; cuatro perfiles SQL: **228 / 1070** MySQL y **228 / 1068** PostgreSQL.
  Ambos motores pasaron READ COMMITTED y REPEATABLE READ. Cero fallos/advertencias del runner;
  primer run fallido y repeticiones locales conservados. Lockfile principal sin cambios,
  trece artifacts temporales, una migración por paquete. No se integró esta ampliación en
  main ni se publicaron versiones. [Versiones, correcciones y evidencia](COMPATIBILITY.md).
- GitHub Actions remoto en **main**: **6/6 trabajos aprobados** en `7553a96`, run `43674756937`,
  2026-11-15 03:59:30–04:02:12 UTC (14 de noviembre en Cancún). PHP 8.3/8.4/8.5:
  209 / 982 generales y 106 / 414 normativos por versión; MySQL 224 / 1055 y PostgreSQL
  224 / 1053. Se revisaron los seis logs: **cero advertencias del runner y cero fallos**.
  Checkout v7.0.1 fijado por SHA, con Node 24, validado localmente y en remoto; resuelve
  las seis advertencias de la ejecución anterior. Rama codex integrada en main con
  autorización mediante fast-forward, sin conflictos ni publicación de versiones.
  [Informe, IDs y límites](CI-REMOTE.md).
- Base local previa: **209 tests / 982 aserciones**, aprobados en Windows con PHP **8.3.36, 8.4.27 y 8.5.12**,
  Laravel **13.42.0**, Passport **13.10.0**, League OAuth2 Server **9.4.1**,
  firebase/php-jwt **7.2.1** y PHPUnit **12.5.44**. SQLite **3.53.4** en PHP 8.3.
- PHPStan/Larastan nivel 5: sin errores y sin baseline. Laravel Pint: aprobado.
- Dos despliegues Linux con HTTPS, APP_ENV=production, OPcache y optimize: Nginx 1.28.3 /
  PHP-FPM 8.3.36 / MySQL 8.4.13 con sesiones en archivos; Apache 2.4.68 / PHP 8.4.27 /
  PostgreSQL 18.7 con sesiones en DB. Seis apps y doce comprobaciones de proxy aprobadas.
- Chromium 159.0.8154.6, Firefox 159.0 y WebKit 27.2: **60 controles** en tres sitios
  registrables por despliegue, con TLS estricto. SSO, cookies Lax, iframe sin autenticación,
  CSRF, Recordarme, dispositivos y logout comprobados.
- Campaña corregida completa: **1805,85 s, 8 usuarios, 15 850 solicitudes, cero errores
  inesperados**. Caída OP de 60 s y pausa de worker de 120 s; pendientes 28/24 durante
  la pausa y 0/0 al cierre. Se conservaron intentos fallidos y se repitió tras corregir
  stat cache del harness. [Resultados, latencias y límites](DEPLOYMENT-VALIDATION.md).
- Esquema del servidor consolidado en una migración inicial; prueba de creación,
  reversión y recreación aprobada. Los ajustes se mantienen en una migración por paquete.
- Matriz completa en Linux PHP 8.3.36: MySQL **8.4.13 / REPEATABLE READ**, **224 pruebas /
  1055 aserciones**; PostgreSQL **18.7 / READ COMMITTED**, **224 / 1053**, sin fallos ni saltos.
  Catorce carreras con hasta ocho procesos por motor: diez de protocolo y cuatro de Recordarme.
  Ver [informe de concurrencia](DATABASE-CONCURRENCY.md) y su JSON sin secretos.
- Recordarme: cookie central host-only con vencimiento absoluto, secretos aleatorios
  guardados como hash, rotación de un uso y revocación de familia ante replay. La pantalla
  `/sso/devices` permite nombrar/revocar dispositivos propios con CSRF y contraseña reciente.
  [Alcance, política estricta y resultados](REMEMBER-DEVICES.md).
- Auditoría: 34 eventos de catálogo cerrado con referencias HMAC; cambios después de commit,
  rollback sin eventos de transición y sink de log opt-in. Instaladores OP/RP repetidos sin
  sobrescrituras ni migraciones duplicadas; diagnósticos JSON y casos negativos aprobados.
  E2E: doctor OP y ambos doctores RP con discovery/JWKS reales aprobados.
  [Comandos, catálogo, garantías y límites](AUDIT-OPERATIONS.md).
- Grupo de conformidad propio: **106 tests / 414 aserciones**, sin saltos; informes JUnit/JSON
  locales. Ver [alcance](OIDC-CONFORMANCE.md) y [resultados](OIDC-CONFORMANCE-RESULTS.md).
- Suite oficial descargada y fijada a `daca6ad2068468d42557ea1152f899cc3ac01a10`.
  Docker Desktop 4.99.0 y WSL 3.0.5 operativos después del primer reinicio. Los cinco
  planes se ejecutaron con TLS privado y HtmlUnit; ver IDs, estados y exports en
  [resultados](OIDC-CONFORMANCE-RESULTS.md). No se necesitó otro reinicio de Windows.
- Revisión de las observaciones OIDC: seis advertencias corregidas; ensayo final con
  58 PASSED, 0 WARNING/FAILED, 16 evidencias REVIEW verificadas y 10 SKIPPED justificados.
  Repetición tras auditoría/operaciones: `official-audit-operations-20261114-215322`,
  mismos conteos y revisión repetida, con huella del OP coincidente con el workspace de esa ejecución.
  OP/worker trasladados al volumen y red Linux; transporte HTTPS 80/80, cuatro simultáneas.
  La compatibilidad posterior modificó transacciones sin repetir el harness oficial;
  sus resultados históricos se conservan. No se solicitó certificación.
- Siete manifiestos Composer: validate --strict aprobado. Composer audit --locked:
  sin avisos conocidos en la consulta realizada; no equivale a auditar el código propio.
- Resolución independiente del cliente: 77 paquetes, sin Passport ni laravel-sso/server.
- Setup de ejemplos ejecutado y repetido conservando bases y secretos.
- E2E con tres servidores HTTP reales: hosts 127.0.0.1, 127.0.0.2 y 127.0.0.3,
  cookies separadas, tres SQLite y tres APP_KEY distintas; el primer navegador conecta
  dos RP con una entrada de credenciales. Un segundo verifica revocación aislada; misma
  identidad, ACL independiente y ausencia de contraseñas RP.
- E2E rechazó login sin CSRF, cerró globalmente desde A, obtuvo 401 en B y procesó las
  tres entregas back-channel. Verificó además recuperación/rotación tras perder la cookie
  de sesión central, revocación de otro dispositivo, POST externo de authorize/logout y rechazo
  CSRF en la confirmación. Los procesos temporales quedaron detenidos.

No ejecutado: navegadores comerciales/Safari en sistemas físicos,
carga distribuida o multijornada ni revisión independiente/certificación externa. Los dos
despliegues, navegadores Linux cross-site y carga local de 30 min sí se ejecutaron. MySQL, PostgreSQL y el harness
OpenID Foundation sí se ejecutaron localmente. La matriz ampliada prueba resoluciones
locked/lowest/latest por PHP y dos aislamientos por motor SQL; no prueba todas las
combinaciones de dependencias/PHP/SQL. El harness oficial no se repitió en esta última fase.

## Matriz requisito → componente → prueba

| Requisito | Componente | Evidencia actual |
| --- | --- | --- |
| Login único entre hosts | Passport + central session | tools/e2e.php: dos RP sin segundo login |
| Identidad / Laravel Auth | EloquentProvisioner + SessionGuard | ClientTest y E2E mismo iss/sub |
| ACL independiente | Gate + ReportPolicy | E2E permiso sólo en A, 403 en B |
| Código de un uso / S256 | AuthCodeRepository + League | ProtocolTest: replay, verifier, plain, callback y cliente |
| ID Token / rotación | SigningKeys / IdTokenValidator | ClientTest negativos y KeyRotationTest |
| Aprovisionamiento | EloquentProvisioner | ClientTest y carreras reales: una identidad, sin email linking/roles/password, evento después de commit |
| Caducidad / suspensión | GlobalSessions | SessionTest y ProtocolTest |
| Logout central nativo | evento Laravel Logout | SessionTest |
| Logout RP | LogoutEndpointController | Conformance/RpInitiatedLogoutTest: sin cookie/hint, cancelación, redirects, cuentas; E2E |
| Back-channel durable | LogoutDeliveries / LogoutController | LogoutTest: 503, reintento, duplicado y sid aislado; E2E |
| Revisión estricta | EnsureGlobalSession | ClientTest OP 503 falla cerrado; E2E B recibe 401 |
| Independencia | manifests / ejemplos | cliente sin Passport; E2E bases y APP_KEY distintas |
| Recordarme | DeviceSessions + SessionGuard | rotación, replay, password reset, expiración y carreras reales; auth_time original conservado |
| Dispositivos propios | DeviceController + middleware central | listado, nombres, cierre individual/otros/todos, aislamiento, contraseña reciente, CSRF y E2E |
| Auditoría | AuditEvent + Audit por componente | AuditTest: rollback, replay, secretos excluidos, duplicados y logs opt-in; ProtocolTest: códigos/tokens reales |
| Instalación y diagnóstico | install-server/install-client/doctor-server/doctor | OperationsTest: conservación, migración real, claves y fallos múltiples; doctores OP/RP reales en E2E |
| Compatibilidad DB / carreras | migraciones y transacciones | SQLite; MySQL 8.4 / PostgreSQL 18 en READ COMMITTED y REPEATABLE READ, catorce carreras por perfil; campaña previa de 30 min |
| Despliegue / cross-site | Nginx/FPM y Apache; Playwright | seis apps optimizadas HTTPS, tres motores de navegador, 60 controles y doce de proxy |
| Perfil Code normativo local | tests/Conformance | **106 tests / 414 aserciones**; no equivale al harness oficial |
| Certificación | OpenID Foundation | harness oficial ejecutado localmente; resultados y revisiones conservados; certificación no solicitada |

## Próxima fase

Los bloques de concurrencia/retención, Recordarme/dispositivos y auditoría/instalación/
diagnóstico, despliegues y carga de 30 min quedan atendidos en el alcance local documentado. Se conserva la reproducción
de los cinco planes OIDC y sus evidencias históricas. La compatibilidad ampliada queda
atendida en su matriz documentada, todavía en rama codex. El destino y retención de auditoría se configuran
en cada anfitrión; sus eventos no sustituyen una outbox durable de auditoría.

1. Revisar e integrar la rama de compatibilidad cuando se solicite; repetir la CI en main.
   La ampliación solicitada ya pasó. Nuevos motores/versiones se eligen según el destino,
   sin convertir esa ampliación abierta en una garantía general.
2. Evaluar un piloto externo y los entornos concretos de destino: navegadores comerciales,
   móviles físicos, carga distribuida/multijornada y fallos distintos de los ensayados.
3. Revisión independiente, evaluación/certificación externa, canal privado de reportes,
   nombre público y split de paquetes;
   autorización explícita antes de publicar versiones.

## Registro de ejecución

El directorio comenzó vacío y se inicializó Git. Composer 2.11.1. PHP por defecto tenía
OPcache de otra ABI; se usó PHP 8.3/8.4 y una copia de php.ini 8.5 aislada en .runtime,
sin modificar la instalación global. OpenSSL necesitó OPENSSL_CONF explícito. PHP
is_readable fallaba bajo sandbox Windows aunque permitía lectura; las verificaciones PHP
se ejecutaron fuera del sandbox. Secretos, claves, bases y logs están ignorados por Git.

Las contradicciones del prompt sobre users se resolvieron con confirmación del usuario:
representaciones Eloquent locales sin credenciales. Los criterios antiguos de "sin tabla
users" se sustituyen por identidad central más usuarios y ACL locales.
