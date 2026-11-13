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
