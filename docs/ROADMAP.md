# Roadmap y evidencia

Actualizado 2026-11-13. Primera vertical integrada **experimental**, sin publicación,
certificación ni recomendación de producción. Una vertical no cierra toda su fase.

| Fase | Estado real | Evidencia / cierre pendiente |
| --- | --- | --- |
| 0 análisis | base completada | versiones/APIs inspeccionadas, arquitectura, decisiones, modelo y matriz |
| 1 fundamento | base local verificada | tres paquetes, providers, Composer, CI configurada, Pint y Larastan; CI remota aún no ejecutada |
| 2 servidor | perfil Code ampliado verificado | GET/POST, query/form_post, prompts, max_age, Basic, errores y at_hash; harness oficial pendiente |
| 3 cliente | validación OIDC ampliada | iss de autorización, at_hash, aud/azp, max_age y Logout Tokens; falta evaluación externa |
| 4 identidad local | implementación inicial verificada | índice único, createOrFirst y ACL independiente; falta probar carreras en motores reales |
| 5 sesiones | parcial | logout RP/back-channel, reintentos y revocación probados; Recordarme y dispositivos pendientes |
| 6 instalación | laboratorio reproducido | tres apps, setup repetible y comandos documentados; falta instalación limpia en otros SO/motores |
| 7 publicación | pendiente | calidad local aprobada; faltan auditoría externa, conformidad, matrices remotas y autorización |

## Resultados ejecutados

- **97 tests / 386 aserciones**, aprobados en Windows con PHP **8.3.36, 8.4.27 y 8.5.12**,
  Laravel **13.42.0**, Passport **13.10.0**, League OAuth2 Server **9.4.1**,
  firebase/php-jwt **7.2.1** y PHPUnit **12.5.44**. SQLite **3.53.4** en PHP 8.3.
- PHPStan/Larastan nivel 5: sin errores y sin baseline. Laravel Pint: aprobado.
- Grupo de conformidad propio: **63 tests / 259 aserciones**, sin saltos; informes JUnit/JSON
  locales. Ver [alcance](OIDC-CONFORMANCE.md) y [resultados](OIDC-CONFORMANCE-RESULTS.md).
- Suite oficial descargada y fijada a `daca6ad2068468d42557ea1152f899cc3ac01a10`.
  Preflight: **bloqueado por Docker ausente**; ningún plan oficial ejecutado.
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

No ejecutado: GitHub Actions remoto, MySQL, PostgreSQL, OpenID Foundation, navegadores
reales ni revisión independiente. La matriz PHP local comparte un lockfile resuelto con
plataforma mínima 8.3; no prueba todas las combinaciones posibles de dependencias.

## Matriz requisito → componente → prueba

| Requisito | Componente | Evidencia actual |
| --- | --- | --- |
| Login único entre hosts | Passport + central session | tools/e2e.php: dos RP sin segundo login |
| Identidad / Laravel Auth | EloquentProvisioner + SessionGuard | ClientTest y E2E mismo iss/sub |
| ACL independiente | Gate + ReportPolicy | E2E permiso sólo en A, 403 en B |
| Código de un uso / S256 | AuthCodeRepository + League | ProtocolTest: replay, verifier, plain, callback y cliente |
| ID Token / rotación | SigningKeys / IdTokenValidator | ClientTest negativos y KeyRotationTest |
| Aprovisionamiento | EloquentProvisioner | ClientTest: restricted, allowed, sin email linking/roles/password |
| Caducidad / suspensión | GlobalSessions | SessionTest y ProtocolTest |
| Logout central nativo | evento Laravel Logout | SessionTest |
| Logout RP | LogoutEndpointController | Conformance/RpInitiatedLogoutTest: sin cookie/hint, cancelación, redirects, cuentas; E2E |
| Back-channel durable | LogoutDeliveries / LogoutController | LogoutTest: 503, reintento, duplicado y sid aislado; E2E |
| Revisión estricta | EnsureGlobalSession | ClientTest OP 503 falla cerrado; E2E B recibe 401 |
| Independencia | manifests / ejemplos | cliente sin Passport; E2E bases y APP_KEY distintas |
| Recordarme | política central persistente | **sin implementar ni demostrar** |
| Compatibilidad DB / carreras | migraciones y transacciones | SQLite secuencial; MySQL/PostgreSQL y cargas pendientes |
| Perfil Code normativo local | tests/Conformance | **63 tests / 259 aserciones**; no equivale al harness oficial |
| Certificación | OpenID Foundation | checkout/preflight preparados; **harness no ejecutado**, certificación no solicitada |

## Próxima fase

1. Ejecutar el harness oficial local al disponer de Docker/TLS, siguiendo OIDC-CONFORMANCE;
   registrar sus resultados reales y revisar la diferencia de perfil S256 obligatorio.
2. Probar consumo concurrente de códigos, aprovisionamiento, revocación y workers en
   MySQL/PostgreSQL. Definir retención/limpieza del estado persistente.
3. Implementar Recordarme central con rotación y revocación por dispositivo; probarlo
   antes de habilitar UI o anunciar soporte.
4. Ampliar eventos de auditoría y comandos de instalación/diagnóstico; verificar Linux limpio.
5. Revisión independiente, canal privado de reportes, nombre público y split de paquetes;
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
