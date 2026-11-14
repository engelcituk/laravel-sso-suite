# Despliegue (criterios previos, proyecto experimental)

No se recomienda producción todavía: faltan conformidad externa, auditoría y validación
operativa/carga prolongada. La matriz local de MySQL 8.4/PostgreSQL 18 está documentada en
[concurrencia](DATABASE-CONCURRENCY.md). Los ejemplos PHP built-in son sólo locales.

Antes de un piloto controlado:

- HTTPS en todos los endpoints y proxy de confianza configurado correctamente. No activar
  allow_http en producción. Issuer exacto y estable; no reescribir hosts según entrada del usuario.
- Cookies Secure/HttpOnly/SameSite=Lax, dominio propio por app, APP_KEY independientes.
  Almacenamiento de sesión de servidor protegido; cifrado de disco o sesiones cifradas.
- Mantener credenciales únicamente en el OP. Login con CSRF, limitación por cuenta/IP,
  recuperación de contraseñas y políticas de seguridad de la organización.
- Claves privadas fuera del directorio público, permisos/ACL restringidos, backups cifrados
  y rotación. Para rotar OIDC, agrega un nuevo kid/paths, genera sso:keys, publica ambas
  claves públicas y cambia signing_kid. Retira la anterior sólo tras caducar sus tokens
  y considerar los hints de logout aún necesarios. Pasar por un piloto de rotación.
- Secretos de cliente en secret store y nunca en repositorio ni logs. No registrar cuerpos
  token, Authorization, cookies ni query strings con code/id_token_hint.
- Procesar sso:deliver-logouts regularmente, alertar por fallos, sincronizar relojes y medir
  el presupuesto de revocación. Elegir intervalos y timeouts explícitos por ruta.
- Restringir URLs remotas exactas, egreso de red y DNS. No ofrecer registro arbitrario de
  endpoints a usuarios no confiables. La allowlist presupone DNS/operadores confiables.
- Dimensionar sso-server.protocol_throttle para el tráfico agregado de los RP: el default
  es 120 solicitudes/minuto/IP, que puede ser insuficiente en modo strict detrás de NAT.
- Respaldar el estado de revocación y recibos; no limpiar esas tablas sin una política de
  retención que impida revivir sesiones. Probar restauración y caídas antes del piloto.
- Programar `sso:prune-server` en el OP y `sso:prune-client` en cada RP según los plazos
  documentados. Las entregas pendientes se conservan; monitorizar y resolver sus fallos.
- Ejecutar migraciones revisadas y pruebas en tu motor/versión real. El mismo default DB
  debe contener Passport y SSO para conservar atomicidad. Redis sigue siendo opcional.

Laravel Auth debe acompañarse de sso.session en cada grupo protegido. Las comprobaciones
ACL son locales y permanecen obligatorias después de autenticar. Publicar una nueva versión
requiere aprobación explícita y completar los criterios de SECURITY/ROADMAP.
