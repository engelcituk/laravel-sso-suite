# Changelog

## Unreleased — pre-alpha

- Paquetes independientes para servidor de identidad, cliente Laravel y contratos
  compartidos; sin frontend, starter kit o biblioteca de permisos obligatorios.
- Authorization Code + PKCE S256, RS256, discovery/JWKS, UserInfo y validación de callback.
  GET/POST, Basic, query/form_post, prompts/max_age, claims, `iss` y `at_hash`.
- Integración con Auth del anfitrión y modo SSO opcional; conservación de cuentas locales
  existentes, credenciales y permisos, con vínculo por `(issuer, subject)`.
- Alta `greenfield` al primer login válido, vínculo explícito en `restricted` y fábrica
  personalizable en `automatic`; sin vinculación automática por coincidencia de email.
- Login central opcional, registro público opt-in, recuperación mediante el broker Laravel
  y asignación de cuentas a aplicaciones. Formularios sustituibles y contratos JSON.
- Servicios públicos para crear, consultar, listar, editar y revocar clientes desde
  un CRUD anfitrión o Artisan; lecturas sin secretos y revocación aislada por aplicación.
- Sesiones revocables, comprobación remota estricta/acotada, logout con confirmación,
  bandeja back-channel, Recordarme central y administración de dispositivos.
- Auditoría después de commit con referencias HMAC, instaladores y diagnósticos por rol.
- Una migración inicial del servidor y una del cliente; contratos sin migraciones.
  Las bases con una migración inicial ya aplicada requieren un procedimiento de actualización propio.
- Pruebas Testbench, HTTP, concurrencia MySQL/PostgreSQL y matrices PHP/dependencias.
  Campañas locales OIDF, navegadores y carga documentadas con sus revisiones y límites.
- Documentación organizada por instalación, integración, administración, operación y
  evidencia, con ejemplos genéricos independientes de un dominio de negocio.

Sin versión publicada, certificación ni afirmación de aptitud para producción.
Consulta el [roadmap](docs/ROADMAP.md) y la [guía de pruebas](docs/TESTING.md) para los
resultados de cada revisión; una campaña histórica no valida automáticamente cambios posteriores.
