# Changelog

## Unreleased — pre-alpha

- Perfil Code ampliado: nonce/state opcionales en OP, prompts combinados/max_age,
  GET/POST, Basic, query/form_post, errores estándar, iss de autorización y at_hash exacto.
- UserInfo POST header/body, rechazo de credenciales ambiguas y errores Bearer JSON.
- Logout externo GET/POST sin hint/cookie, confirmación/cancelación CSRF separada y
  validación de claims opcionales de Back-Channel Logout.
- Migración adicional para nonce nullable; endpoints internos de consentimiento y
  confirmación movidos a /oidc/consent y /oidc/logout/confirm (nombres de ruta preservados).
- 63 casos normativos propios; harness oficial fijado y preparado, sin ejecución todavía.

- Monorepo con contratos, OP basado en Passport y cliente Eloquent independiente.
- Primera vertical Authorization Code + PKCE, RS256, discovery/JWKS/UserInfo y callback.
- Aprovisionamiento automático/restringido por issuer/subject sin credenciales RP.
- Sesiones revocables, comprobación remota estricta/acotada, logout y bandeja back-channel.
- Tres aplicaciones de desarrollo, pruebas Testbench y recorrido HTTP E2E.
- Pint, Larastan/PHPStan, validación Composer y GitHub Actions.

Sin versión publicada, certificación ni afirmación de aptitud para producción.
