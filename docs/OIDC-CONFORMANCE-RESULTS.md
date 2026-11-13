# Resultados reales — incremento OIDC Code

Fecha: 2026-11-13. Estado: perfil del proyecto ampliado y verificado localmente;
**conformidad oficial pendiente**. No se han publicado código, paquetes ni resultados.

| Verificación ejecutada | Resultado |
| --- | --- |
| PHPUnit completo, PHP 8.3.36 | 97 tests, 386 aserciones; aprobado |
| PHPUnit completo, PHP 8.4.27 | 97 tests, 386 aserciones; aprobado |
| PHPUnit completo, PHP 8.5.12 | 97 tests, 386 aserciones; aprobado |
| Grupo propio conformance | 63 tests, 259 aserciones; aprobado; sin saltos |
| Larastan/PHPStan nivel 5 | aprobado sin baseline; Windows ejecutado sin workers |
| Laravel Pint / Composer validate --strict | aprobados |
| Composer audit --locked | sin avisos de vulnerabilidad conocidos en la consulta realizada |
| E2E tres aplicaciones HTTP | aprobado: SSO, ACL, logout/back-channel, POST externo y CSRF |
| OpenID Foundation harness | **NO EJECUTADO**: preflight bloqueado por Docker ausente |

Entorno: Windows, SQLite; Laravel 13.42.0, Passport 13.10.0, League OAuth2 Server 9.4.1,
firebase/php-jwt 7.2.1 y PHPUnit 12.5.44. Se conserva el lockfile anterior y el mínimo
PHP 8.3. No se ha probado toda combinación posible de dependencias ni MySQL/PostgreSQL.

Los casos normativos y su correspondencia están en [OIDC-CONFORMANCE](OIDC-CONFORMANCE.md).
Informes originales locales: `.runtime/conformance/junit.xml`, `result.json` y
`oidf-preflight.json`. No contienen un resultado de certificación. La copia de la suite
oficial se obtuvo de GitLab, revisión `daca6ad2068468d42557ea1152f899cc3ac01a10`, y quedó
en `.runtime/oidf-suite`; ningún contenedor, plan ni módulo oficial se inició.

## Hallazgos corregidos durante la validación

- Nonce/state obligatorios para todos los RP impedían Code sin nonce. Ahora son opcionales
  en el OP y se mantienen obligatorios en el RP propio.
- Errores de Laravel podían redirigir al referer. Los errores de autorización ahora se
  envían sólo a un redirect registrado validado; no hay redirección para destinos inválidos.
- Prompt login/max_age necesitaban un marcador de reautenticación ligado a la solicitud y
  a un nuevo evento Login; el guard por sí solo no demuestra credenciales recientes.
- Basic requería decodificación form de usuario/secreto y rechazo de credenciales mezcladas.
- League serializa el access token con nuevas marcas de tiempo: se serializa una sola vez
  y se calcula at_hash sobre el valor exacto incluido en la respuesta.
- Logout antes exigía cookie/hint coincidentes y no admitía POST externo. Ahora existe una
  confirmación CSRF separada, con cancelación y protección ante cambio de cuenta.
- El RP exigía exp en Logout Tokens, aunque el estándar lo hace opcional. Se valida exp
  cuando existe y siempre se limita iat; nonce y claims presentes con tipos inválidos se rechazan.
- Larastan con workers falló por renombrado del manifiesto Testbench en Windows. El lanzador
  de análisis usa --debug en Windows para ejecutar las mismas reglas en un proceso; no
  se modificó vendor ni se ocultaron errores de tipos.

## Límites y siguiente validación

Falta ejecutar los cinco planes oficiales identificados, sus variantes, revisiones manuales
y casos de navegador real. El Basic oficial incluye módulos sin PKCE; nuestra política
obligatoria S256 sigue activa. Esto requiere resolver el alcance de esa evaluación, sin
afirmar resultados ni inventar exclusiones. Faltan igualmente revisión externa de seguridad,
concurrencia/motores reales y Recordarme. La fase de certificación permanece abierta.
