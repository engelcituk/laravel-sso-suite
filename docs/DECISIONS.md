# Decisiones técnicas

- ADR-001: monorepo de paquetes path Composer, nombres provisionales laravel-sso/*.
- ADR-002: Laravel ^13, PHP ^8.3 y Passport ^13. Versiones resueltas en composer.lock.
- ADR-003: representaciones Eloquent locales sin credenciales, conforme a secciones 6–7
  y confirmación del usuario. La identidad es `(iss, sub)` y nunca el email.
- ADR-004: RS256 por política fija; biblioteca firebase/php-jwt para JWS/JWK.
  Claves OIDC separadas de las claves OAuth de Passport. No criptografía propia.
- ADR-005: inicialmente Authorization Code + S256; sin implicit, password grant,
  registro dinámico de clientes ni refresh OIDC hasta que existan pruebas específicas.
- ADR-006: extensión Bridge de Passport identificada como API de compatibilidad riesgosa;
  mantener pruebas de intercambio real en CI, sin modificar vendor.
- ADR-007: endpoints remotos del RP deben coincidir con allowlist explícita. Sin redirects
  HTTP, sin emisores suministrados por requests y sin descubrimiento multi-tenant abierto.
- ADR-008: SQLite para pruebas locales, MySQL/PostgreSQL en CI antes de declarar soporte
  probado. No dependencia obligatoria de Redis.
- ADR-009: fases abiertas hasta cumplir sus criterios, no simular logout ni conformidad.
- ADR-010: ampliar Code con nonce/state opcionales en OP, S256 obligatorio por defecto, Basic,
  form_post y RFC 9207. El RP propio sigue creando transacciones con state/nonce y sid.
- ADR-011: separar entrada externa GET/POST de OIDC y confirmaciones CSRF locales.
  Reautenticación exige un nuevo Login ligado a la solicitud, sin confiar en un booleano.
- ADR-012: pruebas normativas propias con JUnit/JSON, separadas del harness OIDF fijado
  por revisión. La suite oficial se ejecuta localmente en Docker; sus resultados no
  equivalen a certificación externa ni se confunden con las pruebas propias.
- ADR-013: S256 obligatorio por defecto y para clientes públicos. El usuario autorizó
  compatibilidad sin PKCE sólo mediante inscripción explícita de clientes confidenciales;
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
- ADR-018: una migración inicial por paquete. El servidor crea sus cinco tablas con
  todos los campos e índices definitivos en `create_sso_tables.php`; el cliente conserva
  su propia migración. Durante este desarrollo los cambios se incorporan al esquema
  inicial, sin acumular migraciones incrementales. Consolidar archivos no altera datos
  ni reescribe el historial de bases ya instaladas.

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
