# Roadmap y evidencia

Actualizado 2026-11-17. Integración **experimental**, sin publicación de versiones,
certificación ni recomendación de producción. Una vertical no cierra toda su fase.

**Prioridad de producto: greenfield.** Preparar un Laravel nuevo como servidor central
y conectar proyectos que nacen como clientes es el alcance principal. La compatibilidad
con aplicaciones existentes es adicional; no se exige adaptar universalmente su Auth.
El servidor limpio y el cliente nuevo tienen evidencia HTTP. Tras registrar/configurar
la aplicación, el modo predeterminado `greenfield` crea y vincula la cuenta local en
el primer login validado, incluso con `users` vacía. No exige fábrica propia ni vínculo
manual para personas nuevas. Las cuentas existentes se conservan y no se adoptan por email.
[Contrato y evidencia de este cierre](GREENFIELD-PROVISIONING.md).
La administración descendente añade sistemas con usuarios asignados, alta manual
central y recuperación de contraseña, con registro público opcional y sin stack impuesto.
[Guía de aplicaciones, cuentas y acceso](CENTRAL-ACCOUNTS.md).
La creación de clientes se extrae a `RegistersClients`/`Clients`: tu aplicación puede
componerla desde su propio CRUD, sin ejecutar Artisan, activar el protocolo ni adoptar
un backend administrativo del paquete. [Contrato público](CLIENT-REGISTRATION-SERVICE.md).
`ManagesClients` completa listado, consulta, edición y revocación desde ese CRUD y
Artisan, sin convertir al paquete en una aplicación administrativa.

| Fase | Estado real | Evidencia / cierre pendiente |
| --- | --- | --- |
| 0 análisis | base completada | versiones/APIs inspeccionadas, arquitectura, decisiones, modelo y matriz |
| 1 fundamento | base local y CI remota verificadas | compatibilidad ampliada integrada en main, 14/14: PHP 8.3/8.4/8.5 con locked/lowest/latest y cuatro perfiles SQL, cero advertencias del runner |
| 2 servidor | gestión de clientes, cuentas y asignaciones verificada localmente | servicios públicos para CRUD y Artisan; alta, consulta, edición y revocación por sistema; registro/recuperación y concurrencia SQL; fuentes/campañas OIDF oficiales anteriores separadas |
| 3 cliente | integración agnóstica y modos verificados localmente | guard/modelo descubiertos, rutas Auth convencionales, JSON/Inertia y E2E con cliente nuevo/existente; navegadores/SQL de fases anteriores no repetidos para este ajuste |
| 4 identidad local | alta greenfield verificada localmente; modo opcional previo en main | primer login crea/vincula sólo cuentas nuevas, sin actualizar existentes; 12 casos nuevos, E2E de DB vacía y dos carreras en cuatro perfiles SQL |
| 5 sesiones | funcionalidades y recuperación locales verificadas | Recordarme, dispositivos, revocación y retención; carga actual con caída OP de 60 s y pausa de worker de 120 s, colas 0/0 al cierre; evaluación externa pendiente |
| 6 instalación | instaladores y guía agnóstica verificados localmente | comandos configuran SSO, migraciones acotadas, claves y tareas; 53 controles HTTP en tres Laravel en la última validación de gestión; piloto externo pendiente y campañas previas separadas |
| 7 publicación | pendiente | cierre local de calidad aprobado: Pint global sin hallazgos, PHPUnit en PHP 8.3/8.4/8.5, Larastan y Composer; CI previa histórica; faltan auditoría/certificación externa y autorización para publicar |

## Resultados ejecutados

- **Distribución Composer desde GitHub, 2026-11-17**: rama `composer` del mismo repositorio
  con índice y tres ZIP independientes del commit `fba4d6f`. Archivos ligados a commits
  fijos y checksums; cliente sin Passport/servidor y una migración por paquete Laravel.
  La distribución inicial está disponible. El workflow de `main` incorpora la actualización
  automática y exige aprobar PHP, manifiestos y perfiles SQL antes de actualizar el índice.
  Verificación: **31 controles loopback y 31 por GitHub HTTPS**, instalando únicamente
  dos hosts efímeros por recorrido; autoload, descubrimiento, copia de configuración,
  migración, reinstalación e integridad. Suite **320 / 1677** en PHP 8.3 y Pint global.
  Sin tags, releases ni instalaciones en proyectos del usuario; código de paquetes,
  migraciones, usuarios, contraseñas y diez originales protegidos intactos.
  [Guía de distribución](GITHUB-DISTRIBUTION.md),
  [evidencias](../conformance/github-distribution-results.json).

- **Revisión de documentación, 2026-11-17**: README orientado a instalación, índice por
  tema y ejemplos genéricos de aplicaciones, cuentas y dominios. Se actualizan changelog,
  decisiones, esquema, servicios públicos y alcance del plan de revisión; los informes
  históricos conservan sus resultados y fuentes. Se verifican los 39 documentos Markdown,
  enlaces/anclas locales, bloques JSON y delimitadores de código. Los ejemplos equivalentes
  pasan **56 pruebas / 373 aserciones**, **53 controles HTTP** y Pint global. No cambia
  la lógica de los paquetes ni sus migraciones; los diez archivos originales protegidos
  conservan sus hashes. No se publican versiones.
  [Índice de documentación](README.md),
  [resultados de la revisión](../conformance/documentation-review-results.json).

- **Gestión pública de clientes, 2026-11-17**: `ManagesClients`, `ClientChanges` y
  `ClientDetails` agregan listado paginado sin secretos, consulta, edición parcial y
  revocación definitiva/idempotente a `Clients`. `sso:clients`, `sso:client-update` y
  `sso:client-revoke` usan el mismo contrato que el CRUD anfitrión. El ID, tipo y secreto
  se conservan. Una edición efectiva invalida códigos/access/refresh tokens de ese
  sistema; guardar sin cambios los conserva. Revocar bloquea nuevos flujos, conserva
  asignaciones/cuentas y no cierra sesiones centrales ni afecta otras aplicaciones.
  La emisión y los cambios se serializan por cliente, incluso con acceso abierto;
  un consentimiento pendiente no envía códigos a un callback retirado.
  Calidad: **320 pruebas / 1677 aserciones** por PHP 8.3/8.4/8.5, **21 / 142** casos
  de la API nueva y **106 / 414** OIDC propias. **29 / 180** por cada perfil MySQL/PostgreSQL
  READ COMMITTED/REPEATABLE READ: 21 casos de servicio, cuatro carreras nuevas y cuatro
  regresiones concurrentes. **53 controles HTTP**, Larastan nivel 5 sin baseline,
  Pint global con caché nueva, siete manifiestos Composer, plataforma/auditoría y tres
  doctores aprobados. Fuentes SQL coincidentes y laboratorio detenido. Los diez archivos
  originales protegidos y ambas migraciones conservan sus hashes; no se agregan migraciones,
  se cambian usuarios/contraseñas existentes ni se publican versiones.
  [Guía de CRUD y Artisan](CLIENT-REGISTRATION-SERVICE.md),
  [evidencias verificables](../conformance/client-management-service-results.json).
  No se repitieron OIDF oficial, navegadores cross-site ni carga de 30 minutos para este
  cambio; el piloto externo y la revisión independiente conservan su estado previo.

- **Servicio público de creación de clientes, 2026-11-17**: `ClientDefinition`,
  `RegistersClients` y `CreatedClient` separan la operación del adaptador Artisan.
  Validaciones HTTPS/PKCE/allowlist y persistencia OAuth/SSO transaccional compartidas;
  auditoría después de commit y secreto accesible sólo explícitamente desde el resultado.
  El servicio no escribe entorno, cambia usuarios o activa SSO. El anfitrión conserva
  sus controladores, permisos y frontend; se prueba su uso con Gate incluso con el
  protocolo desactivado. La opción CLI de confiar back-channel permanece en su adaptador.
  Calidad: **299 / 1535** por PHP 8.3/8.4/8.5; **18 / 101** nuevos en MySQL/PostgreSQL
  con ambos aislamientos; **106 / 414** OIDC propias y **53 controles HTTP**, Larastan,
  Pint global, Composer y doctores aprobados. Fuentes SQL coincidentes y laboratorio detenido.
  Sin cambios de migraciones en esta extracción ni publicación de versiones.
  [Guía para CRUD y Artisan](CLIENT-REGISTRATION-SERVICE.md),
  [resultados de esta extracción](../conformance/client-registration-service-results.json).

- **Sistemas, cuentas centrales y recuperación, 2026-11-17**: `sso:client` restringe
  sistemas nuevos a cuentas asignadas; `sso:user --client=...` crea y asigna, con prompt
  oculto o invitación por correo; `sso:client-user` asigna/retira sin sincronizar usuarios.
  Registro público cerrado por defecto, formularios opcionales y contrato JSON. Broker
  Laravel del guard central, tokens con hash/caducidad/throttle/consumo único; recuperación
  sólo del objetivo, sin autologin y con revocación de sesiones/tokens. La inscripción
  y retirada de acceso se comprueban en autorización, consentimiento, emisión y recursos.
  La carrera PostgreSQL RR detectó un snapshot previo al token: se corrigió serializando
  mediante la fila de inscripción; se conservó el fallo y se repitieron cuatro perfiles SQL.
  Calidad final: **281 / 1434** por PHP 8.3/8.4/8.5, **106 / 414** OIDC propias,
  **53 controles HTTP**, **2 / 9** por cada perfil SQL, Larastan, Pint global,
  Composer y tres doctores aprobados, con fuentes SQL coincidentes y laboratorio detenido.
  Se mantiene una migración por paquete: la definición inicial del servidor añade
  `restrict_users` y `sso_client_users` (ocho tablas), sin cambios de esquema en users ni
  aplicación a las bases originales. Sin versiones publicadas ni nueva CI remota.
  [Guía, alcance y reproducción](CENTRAL-ACCOUNTS.md),
  [evidencia de esta fase](../conformance/central-accounts-results.json).

- **Cierre de alta greenfield al primer login, 2026-11-17**: `provision=greenfield`
  predeterminado en la plantilla nueva. El callback validado crea cuenta y vínculo sin
  fábrica del anfitrión ni `sso:link-user`; los logins posteriores conservan todos sus
  atributos. Email ya existente sin vínculo rechaza el alta, nunca adopta la cuenta.
  Sólo cuentas nuevas reciben un hash de un valor aleatorio descartado para la columna
  NOT NULL; no se copian credenciales ni se cambian passwords existentes. Se conservan
  los modos explícitos `restricted` y `automatic`. **264 / 1300** generales en PHP
  8.3/8.4/8.5, **106 / 414** OIDC propias, Pint global, Larastan y doctores aprobados.
  E2E: **37 controles**, cliente con `users` vacía y cliente previo conservado. Dos
  carreras, **2 / 7** por perfil MySQL/PostgreSQL en READ COMMITTED y REPEATABLE READ;
  fuentes coincidentes en contenedor/workspace e intento fallido del harness conservado.
  Una migración por paquete, sin alterar definiciones, usuarios existentes ni secretos
  originales. Sin CI remota ni publicación. Las campañas OIDF/navegadores/carga anteriores
  no se repiten para este ajuste. [Contrato y reproducción](GREENFIELD-PROVISIONING.md),
  [evidencia con hashes](../conformance/greenfield-client-results.json).

- **Integración agnóstica, workspace sobre `3ff3e589`, 2026-11-17**: servidor Laravel
  sin starter kit, sin login previo ni `is_active`; backend/vista opcional y JSON.
  Login del anfitrión prioritario para no eludir MFA. Cliente nuevo/existente con Auth,
  modelo y permisos locales; integración automática de rutas convencionales, control
  explícito de rutas personalizadas y navegación Inertia sin dependencia de frontend.
  Instaladores con `--configure`, migraciones acotadas, claves faltantes y scheduler
  descubierto. **252 pruebas / 1209 aserciones** en PHP 8.3.36/8.4.27/8.5.12, **106 / 414**
  normativas propias, Pint global, Larastan y Composer aprobados. **32 controles HTTP**
  reales con tres hosts desechables y cachés Laravel; ambos modos, CSRF, Code/PKCE,
  logout y conservación de filas/esquema/ACL. Diez archivos originales protegidos
  intactos; una migración por paquete y ninguna definición modificada. Sin cambios
  de dependencias, CI remota ni publicación. OIDF oficial, navegadores, SQL y carga
  anteriores no se repitieron para estos cambios semánticos y conservan sus huellas.
  [Guía directa](QUICKSTART.md), [contratos por stack](STACK-AGNOSTIC.md),
  [alcance e intentos](AGNOSTIC-VALIDATION.md), [JSON](../conformance/agnostic-auth-results.json).

- **Cierre de los 37 archivos señalados por Pint, 2026-11-17**: la ejecución global con
  caché nueva reprodujo los 37 caminos y la repetición posterior pasó con **cero hallazgos**.
  Los 37 ajustes fueron exclusivamente CRLF/LF; comparación de bytes normalizados y tokens
  PHP aprobada, sin cambios de lógica ni esquema. PHPUnit pasó **230 pruebas / 1076
  aserciones** en PHP **8.3.36, 8.4.27 y 8.5.12**; grupo normativo propio **106 / 414**,
  incluido en la suite general. Larastan nivel 5 sin baseline, siete manifiestos Composer
  estrictos, requisitos de plataforma y audit del lockfile aprobados; sin avisos conocidos
  ni paquetes abandonados en la consulta. Lockfile y diez archivos protegidos de ejemplos
  intactos. Una migración servidor, una cliente y contracts ninguna, sin cambios de esquema
  ni credenciales. Las huellas de bytes cambiaron por el formato: OIDC oficial, navegadores,
  carga y matriz SQL conservan sus evidencias anteriores, sin repetirlas ni reetiquetarlas
  como actuales. Sin nueva CI remota ni publicación.
  [Alcance y reproducción](FORMAT-QUALITY.md),
  [resultados y hashes](../conformance/format-quality-results.json).
- **Preparación verificable del piloto, workspace sobre `98b6e47`**: cinco planes OIDC
  oficiales repetidos, 84 FINISHED: **58 PASSED, 0 FAILED/WARNING, 16 REVIEW verificados
  y 10 SKIPPED justificados**. Run privado `official-pilot-verified-20261117-033225`,
  UTC 03:32:25–03:35:24 del 17 de noviembre (22:32:25–22:35:24 del 16 en UTC−05:00).
  **30 casos / 174 controles** de modos con cachés reales, reinicios de FPM/Apache,
  caída/recuperación del OP y sesiones no transferibles; **60 controles cross-site** y
  doce de proxy aprobados. Los cuatro RP conservaron usuarios, hashes, recaller y ACL
  durante la matriz. Dos ensayos aislados MySQL/PostgreSQL adaptaron sólo tablas SSO,
  sin reaplicar la migración registrada ni cambiar la cuenta local sintética.
  Campaña completa: **1806,038 s, ocho usuarios, 15 832 solicitudes, cero errores
  inesperados/workers fallidos**; pausa con 28/24 entregas pendientes y 0/0 al cierre.
  PHPUnit **230 / 1076**, Larastan y Pint de archivos modificados aprobados. Pint global
  Windows señaló entonces 37 archivos preexistentes; el cierre posterior de formato,
  documentado arriba, los corrigió. Este informe conserva el alcance de aquella ejecución.
  Fuentes/inventario coincidentes en workspace y contenedores; diez archivos originales
  protegidos de ejemplos sin cambios. Una migración servidor, una cliente, contracts
  ninguna. Se corrigió el arranque de Gates/Policies de ejemplos con rutas cacheadas;
  no cambió código de paquetes, bases reales ni contraseñas. Sin nueva CI remota ni
  publicación. [Informe, intentos y límites](PILOT-PREPARATION.md),
  [resumen consolidado](../conformance/pilot-preparation-results.json) y
  [campaña actual](../conformance/pilot-deployment-results.json).
- **Modo SSO/Auth local en main**: run `44007989216`, commit
  `249ca73`, **14/14 trabajos aprobados**, UTC 2026-11-17 03:07:58–03:10:47
  (16 de noviembre 22:07:58–22:10:47 en UTC−05:00). Nueve perfiles PHP/dependencias:
  **230 / 1076** generales y **106 / 414** normativos, E2E real y audit aprobados.
  Cuatro perfiles SQL: **246 / 1152** MySQL y **246 / 1150** PostgreSQL, ambos aislamientos.
  Pint (147 archivos), Larastan y manifiestos aprobados; cero advertencias del runner.
  Se corrigieron la lectura de propietario bajo MySQL REPEATABLE READ y un cursor SQLite
  del E2E que bloqueaba el back-channel. Se conservan los intentos fallidos.
  Una migración por paquete; cliente con tres tablas propias. Integración autorizada de
  `codex/optional-client-auth` por fast-forward, de `848b35c` a `249ca73`, sin conflictos.
  Se conservan la rama y sus runs previos 14/14; no se alteraron bases locales ni se
  publicaron versiones. [Contrato y evidencia](OPTIONAL-AUTH.md).
- Compatibilidad ampliada en **main**: **14/14 trabajos aprobados** en `3f55235`, run `43680036096`,
  UTC 2026-11-15 05:12:00–05:14:44 (00:12:00–00:14:44 en UTC−05:00).
  Nueve combinaciones PHP/dependencias: **212 / 994** generales y **106 / 414** normativos
  cada una; cuatro perfiles SQL: **228 / 1070** MySQL y **228 / 1068** PostgreSQL.
  Ambos motores pasaron READ COMMITTED y REPEATABLE READ. Cero fallos/advertencias del runner;
  primer run fallido y repeticiones locales conservados. Lockfile principal sin cambios,
  trece artifacts temporales, una migración por paquete. Integración autorizada mediante
  fast-forward, sin conflictos ni publicación de versiones. Se conservan las validaciones
  previas de rama. [Versiones, correcciones y evidencia](COMPATIBILITY.md).
- GitHub Actions remoto en **main**: **6/6 trabajos aprobados** en `7553a96`, run `43674756937`,
  2026-11-15 03:59:30–04:02:12 UTC (14 de noviembre en UTC−05:00). PHP 8.3/8.4/8.5:
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
- Auditoría: 35 eventos de catálogo cerrado (incluye `identity.linked`) con referencias HMAC; cambios después de commit,
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
  La compatibilidad posterior modificó transacciones sin repetir entonces el harness;
  la preparación actual sí lo repitió y conserva ambos informes. No se solicitó certificación.
- Siete manifiestos Composer: validate --strict aprobado. Composer audit --locked:
  sin avisos conocidos en la consulta realizada; no equivale a auditar el código propio.
- Resolución independiente del cliente: 77 paquetes, sin Passport ni laravel-sso/server.
- Setup de ejemplos ejecutado y repetido conservando bases y secretos.
- E2E con tres servidores HTTP reales: hosts 127.0.0.1, 127.0.0.2 y 127.0.0.3,
  cookies separadas, tres SQLite y tres APP_KEY distintas; el primer navegador conecta
  dos RP con una entrada de credenciales. Un segundo verifica revocación aislada; misma
  identidad, ACL independiente y ausencia de copia de contraseña central en los RP.
- E2E rechazó login sin CSRF, cerró globalmente desde A, obtuvo 401 en B y procesó las
  tres entregas back-channel. Verificó además recuperación/rotación tras perder la cookie
  de sesión central, revocación de otro dispositivo, POST externo de authorize/logout y rechazo
  CSRF en la confirmación. Los procesos temporales quedaron detenidos.

No ejecutado: navegadores comerciales/Safari en sistemas físicos,
carga distribuida o multijornada ni revisión independiente/certificación externa. Los dos
despliegues, navegadores Linux cross-site y carga local de 30 min sí se ejecutaron. MySQL, PostgreSQL y el harness
OpenID Foundation sí se ejecutaron localmente. La matriz ampliada prueba resoluciones
locked/lowest/latest por PHP y dos aislamientos por motor SQL; no prueba todas las
combinaciones de dependencias/PHP/SQL. El harness oficial, los navegadores y la carga
sí se repitieron en la preparación actual del piloto; no equivalen a evaluación externa.

## Matriz requisito → componente → prueba

| Requisito | Componente | Evidencia actual |
| --- | --- | --- |
| Login único entre hosts | Passport + central session | tools/e2e.php: dos RP sin segundo login |
| Identidad / Laravel Auth | EloquentProvisioner + SessionGuard | ClientTest y E2E mismo iss/sub |
| SSO opcional sin cambiar users | IdentityLinks + sso.local/sso.session | 18 casos PHPUnit y CI histórica; 30 casos / 174 controles actuales con cachés/reinicios, cuentas/ACL intactas y caída/recuperación |
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
| Despliegue / cross-site | Nginx/FPM y Apache; Playwright | seis apps HTTPS optimizadas; repetición actual: 60 controles, doce de proxy y 1806,038 s de carga sin errores inesperados |
| Perfil Code normativo local | tests/Conformance | **106 tests / 414 aserciones**; no equivale al harness oficial |
| Certificación | OpenID Foundation | harness oficial ejecutado localmente; resultados y revisiones conservados; certificación no solicitada |

## Próxima fase

**Para usarlo ya en desarrollo:** integra una aplicación propia con la
[guía rápida](QUICKSTART.md), comprueba el alta/vínculo al primer login y prueba sus rutas y
permisos. No hace falta contratar un revisor para instalar el paquete experimental.
La adaptación agnóstica solicitada queda implementada y verificada en el workspace;
su siguiente comprobación práctica es el entorno y frontend concretos del anfitrión.
Las campañas operativas descritas abajo son históricas: deben repetirse sobre el código
elegido para el piloto antes de atribuirle aquellas garantías. El paquete sigue pre-alpha.

Los bloques de concurrencia/retención, Recordarme/dispositivos y auditoría/instalación/
diagnóstico, despliegues y carga de 30 min quedan atendidos en el alcance local documentado. Se conserva la reproducción
de los cinco planes OIDC y sus evidencias históricas. La compatibilidad ampliada queda
atendida e integrada en main, con su matriz remota aprobada. El destino y retención de auditoría se configuran
en cada anfitrión; sus eventos no sustituyen una outbox durable de auditoría.

El modo opcional queda integrado en main mediante fast-forward autorizado y conserva
sus guías y evidencias de pruebas. La preparación local del piloto queda verificada en
el workspace, con OIDC, modos cacheados/reiniciados, navegadores, adaptación SSO-only
y carga completa repetidos. No se presenta como una nueva CI remota ni como piloto externo.
El cierre posterior de los 37 archivos de Pint ya pasó la calidad local; al cambiar sólo
finales de línea, conserva las campañas anteriores con sus huellas originales.
La siguiente fase es un piloto externo con destino concreto y revisión independiente.
Su [plan de ejecución y aceptación](EXTERNAL-PILOT-PLAN.md) ya está preparado: ficha G0
de entorno/responsables, requisitos, 21 casos obligatorios, carga distribuida y observación
de 48 h propuestas, encargo del revisor y puertas de cierre. Sigue pendiente asignar
destino, responsables, revisor, umbrales ratificados y ventanas; no se ha ejecutado el piloto
ni la revisión. El plan mantiene cuentas intactas, una migración por paquete y sin publicación.
La adaptación de bases existentes se ensayó en espacios descartables de ambos motores:
la migración inicial consolidada no se reaplica sola y no se entrega un actualizador de
producción. Las bases y credenciales originales del laboratorio se conservaron.

1. Evaluar un piloto externo y los entornos concretos de destino: navegadores comerciales,
   móviles físicos, carga distribuida/multijornada y fallos distintos de los ensayados.
   Nuevos motores/versiones se eligen según el destino; no hay una garantía general de compatibilidad.
2. Revisión independiente, evaluación/certificación externa, canal privado de reportes,
   nombre público y split de paquetes;
   autorización explícita antes de publicar versiones.

## Interpretar las evidencias

Cada ejecución identifica su fecha, entorno, revisión o huella de fuentes y alcance.
Los informes de fases anteriores conservan sus resultados originales; no acreditan
automáticamente cambios posteriores. Los logs completos, claves y bases del laboratorio
permanecen en directorios privados ignorados por Git; los resúmenes públicos omiten secretos.

El contrato actual conserva el modelo de usuario del anfitrión y sus credenciales locales.
La identidad SSO se vincula mediante tablas propias. Las altas greenfield crean sólo
cuentas nuevas; una cuenta existente requiere un vínculo explícito verificado.
