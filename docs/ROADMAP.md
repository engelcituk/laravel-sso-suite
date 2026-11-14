# Roadmap y evidencia

Actualizado 2026-11-14. Primera vertical integrada **experimental**, sin publicación,
certificación ni recomendación de producción. Una vertical no cierra toda su fase.

| Fase | Estado real | Evidencia / cierre pendiente |
| --- | --- | --- |
| 0 análisis | base completada | versiones/APIs inspeccionadas, arquitectura, decisiones, modelo y matriz |
| 1 fundamento | base local verificada | tres paquetes, providers, Composer, CI configurada, Pint y Larastan; CI remota aún no ejecutada |
| 2 servidor | perfil Code evaluado localmente | cinco planes oficiales en Docker; PKCE por defecto, excepción confidencial explícita, replay y canal de errores corregidos; ver resultados reales |
| 3 cliente | validación OIDC ampliada | iss, at_hash, aud/azp, max_age, Logout Tokens y UserInfo con sub coincidente; falta evaluación externa |
| 4 identidad local | concurrencia local verificada | índice sensible a mayúsculas, recuperación de conflicto y commit; carreras en MySQL/PostgreSQL, ACL independiente |
| 5 sesiones | concurrencia y limpieza verificadas | revocación, outbox con reserva protegida y retención probados; Recordarme y dispositivos pendientes |
| 6 instalación | laboratorios reproducidos | tres apps, Docker Linux, MySQL/PostgreSQL y comandos documentados; faltan otras versiones/entornos de despliegue |
| 7 publicación | pendiente | calidad local aprobada; faltan auditoría externa, conformidad, matrices remotas y autorización |

## Resultados ejecutados

- **155 tests / 635 aserciones**, aprobados en Windows con PHP **8.3.36, 8.4.27 y 8.5.12**,
  Laravel **13.42.0**, Passport **13.10.0**, League OAuth2 Server **9.4.1**,
  firebase/php-jwt **7.2.1** y PHPUnit **12.5.44**. SQLite **3.53.4** en PHP 8.3.
- PHPStan/Larastan nivel 5: sin errores y sin baseline. Laravel Pint: aprobado.
- Esquema del servidor consolidado en una migración inicial; prueba de creación,
  reversión y recreación aprobada. Los ajustes se mantienen en una migración por paquete.
- Matriz completa en Linux PHP 8.3.36: MySQL **8.4.13 / REPEATABLE READ**, **166 pruebas /
  691 aserciones**; PostgreSQL **18.7 / READ COMMITTED**, **166 / 689**, sin fallos ni saltos.
  Diez carreras con hasta ocho procesos por motor, limpieza de estado y recibos durables.
  Ver [informe de concurrencia](DATABASE-CONCURRENCY.md) y su JSON sin secretos.
- Grupo de conformidad propio: **106 tests / 414 aserciones**, sin saltos; informes JUnit/JSON
  locales. Ver [alcance](OIDC-CONFORMANCE.md) y [resultados](OIDC-CONFORMANCE-RESULTS.md).
- Suite oficial descargada y fijada a `daca6ad2068468d42557ea1152f899cc3ac01a10`.
  Docker Desktop 4.99.0 y WSL 3.0.5 operativos después del primer reinicio. Los cinco
  planes se ejecutaron con TLS privado y HtmlUnit; ver IDs, estados y exports en
  [resultados](OIDC-CONFORMANCE-RESULTS.md). No se necesitó otro reinicio de Windows.
- Revisión de las observaciones OIDC: seis advertencias corregidas; ensayo final con
  58 PASSED, 0 WARNING/FAILED, 16 evidencias REVIEW verificadas y 10 SKIPPED justificados.
  OP/worker trasladados al volumen y red Linux; transporte HTTPS 80/80, cuatro simultáneas.
  La fase posterior de concurrencia ya se ejecutó; no se publicó ni se solicitó certificación.
- Siete manifiestos Composer: validate --strict aprobado. Composer audit --locked:
  sin avisos conocidos en la consulta realizada; no equivale a auditar el código propio.
- Resolución independiente del cliente: 77 paquetes, sin Passport ni laravel-sso/server.
- Setup de ejemplos ejecutado y repetido conservando bases y secretos.
- E2E con tres servidores HTTP reales: hosts 127.0.0.1, 127.0.0.2 y 127.0.0.3,
  cookies separadas, tres SQLite y tres APP_KEY distintas; una entrada de credenciales,
  misma identidad, ACL aislada y ausencia de contraseñas RP.
- E2E rechazó login sin CSRF, cerró globalmente desde A, obtuvo 401 en B y procesó las
  dos entregas back-channel. Verificó además POST externo de authorize/logout y rechazo
  CSRF en la confirmación. Los procesos temporales quedaron detenidos.

No ejecutado: GitHub Actions remoto, navegadores reales cross-site, campañas prolongadas
de carga ni revisión independiente/certificación externa. MySQL, PostgreSQL y el harness
OpenID Foundation sí se ejecutaron localmente. La matriz PHP comparte un lockfile resuelto con
plataforma mínima 8.3; no prueba todas las combinaciones posibles de dependencias.

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
| Recordarme | política central persistente | **sin implementar ni demostrar** |
| Compatibilidad DB / carreras | migraciones y transacciones | SQLite; MySQL 8.4 / PostgreSQL 18, diez carreras con procesos independientes; carga prolongada pendiente |
| Perfil Code normativo local | tests/Conformance | **106 tests / 414 aserciones**; no equivale al harness oficial |
| Certificación | OpenID Foundation | harness oficial ejecutado localmente; resultados y revisiones conservados; certificación no solicitada |

## Próxima fase

El bloque de concurrencia y retención queda atendido en los motores/aislamientos
documentados. Se conserva la reproducción de los cinco planes OIDC y sus evidencias.

1. Implementar Recordarme central con rotación y revocación por dispositivo; probarlo
   antes de habilitar UI o anunciar soporte.
2. Ampliar eventos de auditoría y comandos de instalación/diagnóstico; validar otros
   despliegues, navegadores cross-site y campañas prolongadas de carga/fallos de infraestructura.
3. Ejecutar la matriz CI remota y ampliar versiones/aislamientos, sin atribuirle resultados locales.
4. Revisión independiente, evaluación/certificación externa, canal privado de reportes,
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
