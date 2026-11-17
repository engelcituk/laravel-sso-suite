# Documentación

Para instalar el paquete por primera vez, sigue la [guía rápida](QUICKSTART.md).
Los ejemplos usan un servidor en `https://identidad.test` y una aplicación cliente en
`https://app.test`. Son dominios de ejemplo: configura su resolución y TLS en desarrollo,
o sustitúyelos por los dominios de tu entorno. Los IDs, personas y credenciales mostrados
son marcadores o datos sintéticos.

## Instalación y administración

| Guía | Contenido |
| --- | --- |
| [Guía rápida](QUICKSTART.md) | Instalación de servidor y cliente nuevos, cuenta central y primer login |
| [Servidor](INSTALL-SERVER.md) | Requisitos, instalación, autenticación y registro de aplicaciones |
| [Cliente](INSTALL-CLIENT.md) | Conexión, cuenta local, rutas protegidas y comprobación de acceso |
| [Aplicaciones y cuentas](CENTRAL-ACCOUNTS.md) | Alta administrativa, asignaciones, registro público y recuperación |
| [Servicios de gestión de clientes](CLIENT-REGISTRATION-SERVICE.md) | API pública, CRUD propio y comandos de creación, consulta, edición y revocación |
| [Alta al primer login](GREENFIELD-PROVISIONING.md) | Esquema convencional, campos, colisiones y políticas de aprovisionamiento |

## Integración y referencia

| Guía | Contenido |
| --- | --- |
| [Frontend y Auth propios](STACK-AGNOSTIC.md) | Blade, Vue, React, Inertia, rutas personalizadas y límites de integración |
| [Referencia del servidor](INSTALL-SERVER-REFERENCE.md) | Configuración, protocolo, migraciones y operación |
| [Referencia del cliente](INSTALL-CLIENT-REFERENCE.md) | Guard/modelo, ambos modos y fábrica personalizada |
| [Laravel Auth](LARAVEL-AUTH.md) | Integración con el modelo y el guard del anfitrión |
| [Permisos locales](LOCAL-ACL.md) | Autorización por aplicación, Gates y Policies |
| [Extensión](EXTENDING.md) | Contratos, servicios, eventos y personalización |
| [Arquitectura](ARCHITECTURE.md) | Responsabilidades, dependencias y fronteras entre paquetes |
| [Decisiones técnicas](DECISIONS.md) | Políticas y decisiones de diseño |

## Seguridad y operación

| Guía | Contenido |
| --- | --- |
| [Modelo de seguridad](SECURITY-MODEL.md) | Amenazas, controles, confianza y límites |
| [Sesiones y logout](SESSIONS.md) | Caducidad, revocación, comprobación remota y back-channel |
| [Recordarme y dispositivos](REMEMBER-DEVICES.md) | Rotación, confirmación de contraseña y administración por dispositivo |
| [Auditoría y diagnósticos](AUDIT-OPERATIONS.md) | Eventos, instaladores, doctores y scheduler |
| [Despliegue](DEPLOYMENT.md) | Requisitos y comprobaciones del entorno |
| [Resolución de problemas](TROUBLESHOOTING.md) | Síntomas, causas y verificaciones |
| [Piloto externo y revisión](EXTERNAL-PILOT-PLAN.md) | Plan pendiente de ejecución, criterios y responsabilidades |

## Pruebas y evidencias

El [roadmap](ROADMAP.md) resume el estado y los pendientes. La [guía de pruebas](TESTING.md)
describe cómo validar cambios. Cada informe siguiente corresponde a una ejecución y
unas fuentes concretas; sus cifras no se atribuyen automáticamente al código posterior.
Las pruebas locales y los ensayos OIDF no equivalen a certificación o revisión independiente.

| Informe | Alcance |
| --- | --- |
| [Integración agnóstica](AGNOSTIC-VALIDATION.md) | Auth, frontend y recorrido HTTP en instalaciones independientes |
| [Auth opcional](OPTIONAL-AUTH.md) | Modo local/SSO y validación histórica de compatibilidad |
| [Conformidad OIDC](OIDC-CONFORMANCE.md) | Perfil implementado, pruebas propias y reproducción del harness |
| [Resultados OIDC](OIDC-CONFORMANCE-RESULTS.md) | Estados oficiales, revisiones y límites de las campañas registradas |
| [OIDF en Windows](OIDF-WINDOWS-SETUP.md) | Entorno y reproducción local con Docker |
| [CI remota](CI-REMOTE.md) | Ejecución histórica de la matriz inicial |
| [Compatibilidad](COMPATIBILITY.md) | PHP, resoluciones de dependencias y perfiles SQL ampliados |
| [Concurrencia SQL](DATABASE-CONCURRENCY.md) | Carreras, aislamiento, reintentos y retención |
| [Despliegues y carga](DEPLOYMENT-VALIDATION.md) | Servidores web, navegadores y campañas locales registradas |
| [Preparación del piloto](PILOT-PREPARATION.md) | Repetición local de OIDC, navegadores, carga y modos |
| [Formato y calidad](FORMAT-QUALITY.md) | Corrección de formato y verificaciones de esa revisión |

Para contribuir o informar incidencias consulta [CONTRIBUTING](../CONTRIBUTING.md),
[SECURITY](../SECURITY.md) y [CHANGELOG](../CHANGELOG.md).
