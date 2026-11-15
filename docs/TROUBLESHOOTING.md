# Solución de problemas

| Síntoma | Verificación |
| --- | --- |
| invalid_sso_transaction | issuer exacto, estado/nonce, expiración, cookie SameSite y secreto/callback registrados |
| Untrusted OIDC endpoint | allowed_urls debe contener la URL exacta; no se siguen redirects |
| Acceso denegado al primer login | provision=restricted necesita identidad preaprobada; automatic no asigna roles |
| Logout local pero global pendiente | OP accesible, confirmación central, worker y sso_logout_deliveries |
| Vuelve a pedir login cada pocos minutos | access token corto; redirección al OP debe recuperar sesión central |
| is_active ausente | agregar campo o configurar active_column del modelo central |
| Table already exists al publicar | no repetir publicación con timestamps nuevos; revisar duplicados antes de migrar |
| Discovery 403 sólo en Nginx | una regla genérica de archivos ocultos puede bloquear /.well-known; conservar una excepción exacta para discovery |
| Callback 400 / UserInfo falla sólo en Apache | comprobar que Authorization llega al entorno PHP; conservar el manejo del .htaccess Laravel o la configuración probada del laboratorio |
| Firefox rechaza TLS privado | confiar la CA mediante la política del navegador y comprobar issuer/series únicas; no ignorar errores HTTPS como solución |
| Iframe cross-site no recupera SSO | esperado con cookies Lax; usar navegación principal según el flujo comprobado |
| OpenSSL no genera claves en Windows | configurar OPENSSL_CONF a extras/ssl/openssl.cnf del PHP usado |
| OPcache ABI incompatible | usar PHP/extensiones de la misma versión; no ignorar errores de plataforma |
| PHPUnit no puede abrir archivo existente | comprobar permisos y, en sandbox Windows, el resultado de PHP is_readable |

Entorno inicial: PHP por defecto 8.5.12 cargaba OPcache de otra ABI. Las primeras pruebas
se ejecutaron con el binario Herd PHP 8.3.36 y OPENSSL_CONF explícito, sin cambiar la
instalación global. El sandbox Windows permitía leer pero hacía que is_readable devolviera
false; las comprobaciones PHP se ejecutaron fuera de ese sandbox. No es un requisito
normal de instalación del paquete.

`php artisan sso:doctor-server --json` comprueba el OP local; `sso:doctor --offline --json`
el RP local y `sso:doctor --json` añade metadatos/RS256/S256 y JWKS. Los códigos estables
separan esquema, claves, guard, configuración y outbox atrasada; mensajes y límites en
[operaciones](AUDIT-OPERATIONS.md). No valida credenciales,
no comprueba conformidad y no sustituye una prueba de login completo. Evita compartir
traces con tokens, secretos, cookies o cuerpos del endpoint token.

Las configuraciones reales Nginx/FPM y Apache, las pruebas de proxy y los navegadores
están en [validación de despliegues](DEPLOYMENT-VALIDATION.md). Si se altera un indicador
de pausa del laboratorio desde otro proceso PHP, hay que invalidar la caché de stat;
el harness ya lo hace y exige drenaje de la outbox para aprobar la campaña.
