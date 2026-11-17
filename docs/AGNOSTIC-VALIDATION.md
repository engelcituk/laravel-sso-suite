# Integración agnóstica: validación local

Esta ejecución es histórica. El [cierre posterior de alta greenfield](GREENFIELD-PROVISIONING.md)
cambia la política predeterminada y repite las pruebas sobre sus propias fuentes; los
resultados de esta página conservan el alcance y las huellas originales.

2026-11-17. **El servidor funciona sin starter kit ni login previo; el cliente conserva
Auth y el modelo del anfitrión.** Esta fase añade comportamiento y lo verifica en el
workspace sobre `3ff3e589142bdf9cb00c26de2fb4a34da41c1874`. No se ha integrado, publicado
ni ejecutado una nueva CI remota. [Resumen con hashes](../conformance/agnostic-auth-results.json).

## Cambios comprobados

- Servidor con cuentas Laravel estándar, sin `is_active`: backend opcional de contraseña,
  vista mínima sustituible y contratos JSON de sesión/login/consentimiento/logout.
- Prioridad de autenticación del anfitrión en modo `auto`: el POST alternativo queda
  cerrado si existe login del proyecto, para no eludir MFA/políticas.
- Validación de contraseña sin rehash ni cambios de atributos; logout SSO preserva
  remember_token. CSRF, sesión regenerada, redirecciones acotadas y limitación de intentos.
- Cliente descubre guard/modelo de Auth e integra rutas convencionales `web`. Rutas
  personalizadas tienen patrones o middleware explícito; otros guards no se adivinan.
- Navegación Inertia mediante 409/X-Inertia-Location y errores JSON 401/403, sin dependencia
  de Inertia ni frontend. El protocolo continúa con navegación completa.
- Instaladores con configuración explícita, migraciones acotadas y claves faltantes;
  tareas de mantenimiento registradas sin editar rutas del anfitrión.

## Calidad repetida sobre estos cambios

| Comprobación | Resultado |
| --- | --- |
| PHP 8.3.36 / PHPUnit | 252 pruebas, 1209 aserciones, aprobado |
| PHP 8.4.27 / PHPUnit | 252 pruebas, 1209 aserciones, aprobado |
| PHP 8.5.12 / PHPUnit, ini aislado | 252 pruebas, 1209 aserciones, aprobado |
| Grupo OIDC propio, incluido en la suite | 106 pruebas, 414 aserciones, aprobado; no es el harness OIDF |
| Pint global, caché nueva | Aprobado |
| Larastan nivel 5 | Cero errores, sin baseline |
| Composer | Siete manifiestos estrictos y requisitos de plataforma aprobados |
| Audit del lockfile | Cero avisos conocidos y cero paquetes abandonados en esta consulta |

Se añaden **22 casos**: diez de servidor, siete de cliente y cinco de instalación.
Cubren modelo estándar, login nativo prioritario, contrato JSON, CSRF real, destinos,
backend alternativo bloqueado, integración automática/manual, otros guards, modos no
transferibles, respuestas Inertia, scheduler y preservación de entorno/datos.
Los doctores del servidor y ambos clientes (`--offline`) también pasan en los hosts instalados.
El escritor de entorno prueba secretos con comillas, barras y `$`, claves duplicadas y
rechazo de claves ajenas a SSO. Los instaladores se repiten y dejan pendiente una
migración centinela del anfitrión. Se corrigieron dos llamadas no garantizadas por el
contrato StatefulGuard añadiendo comprobación real de SessionGuard; no se silenciaron reglas.

## HTTP real: tres aplicaciones desechables

`tools/agnostic-e2e.php` crea tres hosts Laravel independientes, sin frontend ni starter
kit: servidor sin login propio, cliente nuevo sin login local y cliente con Auth nativo.
Cada uno tiene DB, APP_KEY y cookies propias. El fixture crea las cuentas sintéticas
**antes** de instalar SSO; el paquete debe conservar todos sus atributos y ACL.

Ejecución final: `.runtime/agnostic-auth/live-20261117-154245-e1306e/result.json`.
Fechas exactas y los 32 controles figuran en el JSON público. **32/32 aprobados**:

- Instaladores, claves, config cache y route cache reales; tareas descubiertas en los tres hosts.
- Login opcional sin columna adicional; POST sin CSRF rechazado; login JSON válido.
- Code/PKCE y callback con HTTP real; segundo cliente reutiliza la sesión central.
- Cuentas locales y permisos independientes; migraciones pendientes del anfitrión no ejecutadas.
- Cambio a local rechaza la sesión SSO, login nativo funciona y vuelta a SSO rechaza esa sesión nativa.
- Las rutas cacheadas del cliente se conservan durante el cambio; se reconstruye configuración
  y se reinicia su servidor. Credenciales locales bloqueadas con SSO activo.
- Logout central, revocación estricta en ambos clientes y bandeja back-channel vacía al cierre.
- Esquema, filas completas de users y ACL idénticos antes/después en cada aplicación.

Los servidores son loopback HTTP de desarrollo, con SQLite y **OPcache PHP desactivado**;
las cachés Laravel permanecen activas. El proceso detiene únicamente sus propios servidores.
No se reutiliza ni reinicia infraestructura del usuario.

## Intentos conservados y correcciones del harness

Las carpetas privadas anteriores no se borraron:

| Ejecución | Resultado / aprendizaje |
| --- | --- |
| `live-20261117-150744-1e3ec2` | Falló el boot del fixture por llamar a `Application::useVendorPath`, API inexistente; se corrigió |
| `live-20261117-150815-6ce424` | Comparación incorrecta de slash final en redirect a la raíz; se corrigió |
| `live-20261117-151133-cdaba7` | Configuración HTTP antigua durante cambio rápido de modo |
| `live-20261117-151520-c11d7b` | Se confirmó OPcache de desarrollo obsoleto frente al archivo cacheado nuevo; se desactivó en los servidores del harness |
| `live-20261117-151708-9d9b74` | 29 controles aprobados |
| `live-20261117-152149-a73112` | 32 controles aprobados, añadiendo scheduler |
| `live-20261117-153610-d66544` | Repetición: 32 aprobados |
| `live-20261117-154245-e1306e` | Repetición final tras cerrar la selección explícita de login: 32 aprobados |

Esto no prueba recargas de OPcache de producción. La guía exige reconstruir cachés y
recargar los procesos persistentes con el mecanismo del entorno. Los logs Artisan
privados pueden contener credenciales generadas del laboratorio; no se publican. El
resumen público contiene nombres de controles, resultados y hashes, sin secretos.

## Conservación y límites

Las **diez bases, archivos de entorno y claves originales** protegidos de los ejemplos
coinciden byte a byte con la huella anterior al trabajo. No se ejecutó el setup/E2E
antiguo sobre esas bases. Se conserva exactamente una migración servidor, una cliente
y ninguna en contracts, **sin modificar sus definiciones**. No se cambiaron manifiestos,
lockfile, usuarios, contraseñas ni versiones publicadas.

Esta fase comprueba el contrato independiente del frontend. No ejecutó compilaciones
reales de Vue/React ni de cada starter kit, ni una SPA remota/API stateless. Tampoco
repitió OIDF oficial, navegadores cross-site, matriz SQL o campaña de 30 minutos para
estos cambios semánticos. Esas evidencias anteriores conservan sus fuentes y alcance;
no se atribuyen a este workspace. No equivale a piloto externo, auditoría independiente
ni certificación OpenID. El paquete continúa experimental.

## Reproducir

Desde el repositorio con dependencias instaladas:

```sh
composer test
composer test:conformance
composer lint
composer analyse
composer validate --strict
composer check-platform-reqs
composer audit --locked
php tools/agnostic-e2e.php
```

Para repetir la matriz usa los ejecutables PHP 8.3/8.4/8.5 con sus ini compatibles.
En Windows puede hacer falta `OPENSSL_CONF`; el entorno PHP 8.5 de esta prueba usa un
ini aislado, sin modificar la instalación global. El E2E necesita `proc_open`, SQLite,
OpenSSL y puertos loopback 9300/9301/9302 libres. Sus datos quedan ignorados en `.runtime`.

[Guía rápida](QUICKSTART.md) · [Contratos y stacks](STACK-AGNOSTIC.md) · [Roadmap](ROADMAP.md).
