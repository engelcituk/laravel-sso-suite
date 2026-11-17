# Decisiones técnicas

- ADR-001: monorepo de paquetes path Composer, nombres provisionales laravel-sso/*.
- ADR-002: Laravel ^13, PHP ^8.3 y Passport ^13. Versiones resueltas en composer.lock.
- ADR-003 (sustituida por ADR-033): inicialmente representaciones Eloquent locales sin
  credenciales. El vínculo de identidad se mantiene por `(iss, sub)`, nunca por email.
- ADR-004: RS256 por política fija; biblioteca firebase/php-jwt para JWS/JWK.
  Claves OIDC separadas de las claves OAuth de Passport. No criptografía propia.
- ADR-005: inicialmente Authorization Code + S256; sin implicit, password grant,
  registro dinámico de clientes ni refresh OIDC hasta que existan pruebas específicas.
- ADR-006: extensión Bridge de Passport identificada como API de compatibilidad riesgosa;
  mantener pruebas de intercambio real en CI, sin modificar vendor.
- ADR-007: endpoints remotos del RP deben coincidir con allowlist explícita. Sin redirects
  HTTP, sin emisores suministrados por requests y sin descubrimiento multi-tenant abierto.
- ADR-008: SQLite y matrices reales MySQL/PostgreSQL. Distinguir motores/aislamientos
  ejecutados localmente de las [ejecuciones CI remotas](CI-REMOTE.md). Redis no es obligatorio.
- ADR-009: fases abiertas hasta cumplir sus criterios, no simular logout ni conformidad.
- ADR-010: ampliar Code con nonce/state opcionales en OP, S256 obligatorio por defecto, Basic,
  form_post y RFC 9207. El RP propio sigue creando transacciones con state/nonce y sid.
- ADR-011: separar entrada externa GET/POST de OIDC y confirmaciones CSRF locales.
  Reautenticación exige un nuevo Login ligado a la solicitud, sin confiar en un booleano.
- ADR-012: pruebas normativas propias con JUnit/JSON, separadas del harness OIDF fijado
  por revisión. La suite oficial se ejecuta localmente en Docker; sus resultados no
  equivalen a certificación externa ni se confunden con las pruebas propias.
- ADR-013: S256 obligatorio por defecto y para clientes públicos. La compatibilidad
  sin PKCE exige inscripción explícita de clientes confidenciales;
  no cambia el RP Laravel ni permite plain o verifier incorrecto cuando se usa PKCE.
- ADR-014: en Code, perfil/email proceden de UserInfo con sub coincidente; replay de código
  revoca sólo los tokens ligados a ese código y al cliente autenticado.
- ADR-015: Request Objects no soportados siguen rechazados. Sólo response_mode puede
  orientar la entrega de ese rechazo, con parsing limitado; no se usa ninguna otra claim.
- ADR-016: claims normales configurables y selección userinfo/id_token ligada al código
  y al token, con consentimiento y filtros de valores. Datos ausentes no se sintetizan.
  acr se anuncia como 0; nunca se copia una clase solicitada que el login no acredita.
- ADR-017: REVIEW conserva el estado oficial. La auditoría local verifica semántica de
  DOM/formularios/CSRF y respuestas 400; los SKIPPED se contrastan con discovery y pruebas
  negativas de capacidades. Nada se convierte artificialmente en PASSED.
- ADR-018: una migración inicial por paquete Laravel. El servidor crea sus ocho tablas con
  todos los campos e índices definitivos en `create_sso_tables.php`; el cliente conserva
  su propia migración. Durante este desarrollo los cambios se incorporan al esquema
  inicial, sin acumular migraciones incrementales. Consolidar archivos no altera datos
  ni reescribe el historial de bases ya instaladas. Contracts no contiene migraciones.
- ADR-019: canje, revocación y limpieza final se serializan mediante el bloqueo de sid;
  se leen vinculaciones actuales y se conserva la señal de replay tras rollback. La matriz
  prueba MySQL REPEATABLE READ y PostgreSQL READ COMMITTED con conexiones independientes.
- ADR-020: outbox con SKIP LOCKED y generación de reserva en attempts. Un resultado
  tardío no puede modificar un intento posterior. El transporte sigue siendo al menos una vez.
- ADR-021: retención con comandos explícitos por rol; no borrar entregas pendientes,
  hints recientes, recibos útiles ni grants ajenos. Caducidad explícita del índice local.
- ADR-022: claves issuer/subject/sid sensibles a mayúsculas y evento de aprovisionamiento
  después del commit. Las transacciones del llamador deben reintentarse completas ante deadlock.
- ADR-023: Recordarme sólo en el OP, por dispositivo, con selector/verificador aleatorios,
  hash en base, cookie cifrada host-only y vencimiento absoluto. Se retira el recaller nativo
  compartido; los RP no adquieren credenciales persistentes propias.
- ADR-024: rotación de un uso. Replay con secreto correcto revoca la familia; no hay
  ventana de reutilización, ni siquiera para recuperaciones simultáneas de una cookie.
- ADR-025: restaurar no avanza auth_time ni acredita contraseña reciente. La administración
  propia requiere CSRF y confirmación del proveedor central; reset de contraseña/fingerprint
  de credenciales invalida dispositivos. Los metadatos del navegador no autentican hardware.
- ADR-026: bloqueo dispositivo → sesión y lectura actual de sus sid. Revocar un sid
  retira el acceso del dispositivo completo y sus futuras recuperaciones Recordarme.
- ADR-027: AuditEvent v1 compartido sin framework, catálogo cerrado y referencias HMAC
  por rol/tipo. Emisión después de commit en la conexión propietaria; sink privado opt-in,
  sin modelos, credenciales ni tabla/outbox nueva. Límites de entrega documentados.
- ADR-028: instaladores por rol conservan archivos y timestamp de una única migración.
  Migrar/generar claves requiere opción explícita; doctores locales/online separados,
  informe JSON sin secretos y código de salida útil para comprobaciones automatizadas.
- ADR-029: validación operativa local con dos SAPI web optimizados, tres sitios por perfil,
  navegadores Chromium/Firefox/WebKit y 30 min de carga mixta. Ingress conserva Authorization,
  discovery y origen HTTPS; CA privada con series únicas, sin ignorar TLS ni confiarla en Windows.
  El harness invalida stat cache de indicadores externos y sólo aprueba con ambas outbox vacías.

- ADR-030: matriz PHP locked/lowest/latest con plataforma real en resoluciones alternativas;
  lockfile principal conservado, auditoría activa y artifacts temporales sin secretos.
  Calidad estática/formato con herramientas locked; compatibilidad funcional en los nueve jobs.
- ADR-031: MySQL/PostgreSQL se evalúan en READ COMMITTED y REPEATABLE READ. Las conexiones
  hijas verifican el nivel efectivo. Las filas de dispositivo/sesión actúan como anclas de
  versión para evitar revocaciones con snapshots antiguos; no se añaden columnas.
- ADR-032: conservar SQLSTATE 40001 numérico entre savepoints y reintentar únicamente
  transacciones completas, con presupuesto limitado. Si el llamador posee la transacción,
  debe reintentarla completa. No se reintentan errores de integridad arbitrarios ni se
  publican callbacks propios de intentos revertidos. Ver [resultados](COMPATIBILITY.md).

- ADR-033: modo explícito mediante SSO_CLIENT_ENABLED, desactivado por defecto. La integración
  extiende Auth local sin modificar el esquema de users ni las contraseñas existentes. La identidad se guarda
  en sso_identities; las sesiones incluyen el propietario local. Una migración inicial
  por paquete, sin migraciones incrementales. No hay conversión automática de esquemas
  ya instalados ni vinculación por correo. SsoOnly pasa a ser opt-in para modelos exclusivos.
- ADR-034: las altas personalizadas del modo `automatic` usan una fábrica del anfitrión dentro de la transacción;
  los usuarios vinculados no sincronizan perfil ni credenciales. Clase invocable cacheable,
  misma conexión, unicidad de identidad, rollback y eventos después de commit. La lectura
  del propietario bloqueada obtiene estado actual bajo MySQL REPEATABLE READ.
- ADR-035: sso.local protege rutas nativas y sso.session protege recursos en ambos modos.
  El cambio de origen exige nuevo login; logoutCurrentDevice conserva el recaller nativo.
  El modo local es política del operador, nunca fallback automático ante caída del OP.

- ADR-036: `greenfield` es la política predeterminada para clientes nuevos. La fábrica
  incluida crea cuenta local y vínculo en la misma transacción al primer login válido;
  rechaza colisiones de email y conserva las cuentas ya vinculadas. `restricted` mantiene
  la vinculación explícita y `automatic` permite esquemas personalizados.
- ADR-037: la administración central crea cuentas y asigna acceso por aplicación sin
  sincronizar credenciales. Registro público opt-in sin asignaciones; recuperación con
  el broker Laravel del guard central y revocación de las sesiones de su cuenta objetivo.
- ADR-038: `RegistersClients` y `ManagesClients` son contratos públicos compartidos por
  CRUD anfitriones y Artisan. Lecturas sin secretos; edición parcial con invalidación de
  códigos/tokens; revocación definitiva/idempotente por aplicación. La emisión y los
  cambios usan un bloqueo por cliente con reintentos de serialización. El anfitrión
  conserva las rutas, autorización y frontend de su administración.

## Fuentes oficiales consultadas (2026-11-13)

- https://laravel.com/docs/13.x/releases
- https://laravel.com/docs/13.x/passport
- https://laravel.com/docs/13.x/authentication
- https://github.com/laravel/passport/tree/13.x
- https://oauth2.thephpleague.com/authorization-server/customization/
- https://openid.net/specs/openid-connect-core-1_0.html
- https://openid.net/specs/openid-connect-discovery-1_0.html
- https://openid.net/specs/openid-connect-rpinitiated-1_0.html
- https://openid.net/specs/openid-connect-backchannel-1_0.html
- https://www.rfc-editor.org/rfc/rfc9700.html

La documentación 13.x confirma PHP mínimo 8.3. El manifiesto Passport 13.x admite
Illuminate 13 y League OAuth2 Server 9.2. Las APIs se verificarán además contra vendor
instalado, sin copiar implementaciones de terceros.
