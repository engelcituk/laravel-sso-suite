# Plan del piloto externo y revisión independiente

Fecha: 2026-11-17, UTC−05:00. **Estado: plan preparado; ejecución y revisión pendientes.**
Destino, operadores, revisor, ventanas y fecha de inicio están por asignar. Los tamaños,
duraciones y umbrales indicados son una propuesta para concretar el piloto; no son
resultados medidos ni garantías de capacidad. Se fijan antes de ejecutar, en G0.

## 1. Objetivo y alcance

Evaluar un OP y dos RP en un staging externo aislado, con la integración real del
anfitrión, navegadores comerciales y dispositivos físicos. La revisión independiente
abarca servidor, cliente, contracts, persistencia, integración de Auth/ACL y operación.
El resultado será una decisión documentada sobre ese destino y esa revisión del código.

La base disponible incluye la [preparación local](PILOT-PREPARATION.md), el
[cierre de calidad](FORMAT-QUALITY.md) y la validación de
[cuentas centrales](CENTRAL-ACCOUNTS.md), [alta greenfield](GREENFIELD-PROVISIONING.md)
y [gestión pública de clientes](CLIENT-REGISTRATION-SERVICE.md).
Sus informes conservan las huellas originales:
las pruebas se deben reproducir sobre la instantánea que se entregue al revisor y se
despliegue. Ningún resultado externo figura como ejecutado en este plan.

Restricciones de toda la campaña:

- Una migración en `server`, una en `client` y ninguna en `contracts`; sin archivos
  incrementales nuevos. La migración inicial ya registrada no se reaplica automáticamente.
- El esquema y las filas de `users` del destino permanecen intactos: ID, perfil, hash,
  `remember_token`, relaciones y permisos. No se copian contraseñas ni hashes centrales.
- Usar cuentas sintéticas **ya disponibles**, suministradas por el operador, sin datos
  de producción. Configurar el cliente con `provision=restricted`; vincular únicamente
  `iss/sub` verificados en las tablas SSO. No activar la fábrica automática.
- No crear, registrar, borrar, suspender ni resetear cuentas durante el piloto. Casos
  que necesitan modificar credenciales se revisan estáticamente o en los fixtures
  efímeros de la suite, sin conexión a las bases del destino.
- No publicar versiones, tags, releases, paquetes, imágenes ni resultados sensibles.
  La campaña no inicia un trámite de certificación; el encargo de revisión corresponde
  al responsable designado en G0.

La autenticación nativa puede tener listeners de último acceso, rehash automático o
rotación de recaller que escriban en `users`. El operador debe identificar esos caminos
antes del piloto y preparar una integración que respete su inmutabilidad. No marcar
Recordarme nativo ni ejecutar un logout nativo que rote `remember_token` en esas cuentas.
Un flujo necesario que no pueda cumplirlo bloquea el arranque; no se omite silenciosamente.
Recordarme central se prueba con la política de dispositivos del paquete.

## 2. Ficha de entorno y responsables — G0

Completar esta ficha en un registro privado de la campaña. No poner secretos en el repositorio.

| Dato obligatorio | Contenido a fijar |
| --- | --- |
| Destino y propietario | Proveedor/ubicación, responsable del OP, responsable de cada RP, operador de DB y responsable de incidentes |
| Revisión e independencia | Persona/empresa revisora, experiencia OAuth/OIDC/Laravel, relación con los autores y conflictos declarados |
| Alcance autorizado | Hosts/IP de staging, rutas, fuentes de tráfico, horarios, pruebas de fallos permitidas y exclusiones |
| Instantánea | Commit, estado del árbol, SHA256 de fuentes, lockfile, inventario Composer, imágenes por digest y configuración redactada |
| Topología | OP + RP A + RP B, dominios, issuer exacto, callbacks y logout registrados, proxy/LB, réplicas y afinidad de sesiones si existe |
| Versiones | OS, PHP y SAPI, Laravel, Passport, League, JWT, motor/versión SQL, aislamiento real, driver de sesiones y extensiones |
| Cuentas de prueba | Referencias opacas de cuentas preexistentes, operador que comprobó `iss/sub`, permisos distintos en A/B y dispositivos de prueba |
| Políticas temporales | TTL de tokens/sesiones/Recordarme, `max_age`, intervalo de comprobación, timeout HTTP, retenciones, frecuencia de worker/scheduler |
| Carga y límites | Usuarios concurrentes, pausas, distribución por IP/NAT, tasa esperada por endpoint y rate limits del anfitrión/OP |
| Operación | Métricas, alertas, contacto privado probado, custodia de evidencias, retención y procedimiento de parada/recuperación |
| Aceptación | Umbrales ratificados de la sección 6 y responsables de cerrar cada hallazgo; fecha/ventana de ejecución |

G0 termina cuando todos esos campos tienen un responsable y un valor. Los umbrales
pueden ajustarse con justificación **antes** de medir, conservando el original y la
revisión del plan. Las restricciones sobre cuentas, migraciones y publicación no son
excepciones negociables del piloto.

## 3. Requisitos del entorno

| Área | Requisito y verificación de entrada |
| --- | --- |
| Aislamiento | Staging separado de producción, acceso limitado al operador/revisor y cuentas sintéticas. Generadores externos al host de las apps; no probar sistemas de terceros fuera del alcance |
| Perfil de software | Elegir un perfil concreto del destino. La evidencia previa cubre PHP 8.3–8.5 y determinados perfiles MySQL/PostgreSQL; otras combinaciones requieren su propia validación. No actualizar dependencias durante una medición |
| Dominios y TLS | Tres sitios registrables distintos si el destino es cross-site; DNS estable, certificados válidos para los navegadores elegidos y TLS estricto también entre servidores. `allow_http=false`; no desactivar verificaciones |
| Proxy | Lista explícita de proxies confiables; probar sustitución de cabeceras reenviadas y rechazo de hosts desconocidos; issuer y callbacks no dependen de entrada del visitante |
| Separación | APP_KEY, cookies, claves y DB independientes por app. Dentro del OP, Passport y SSO comparten la conexión transaccional configurada; en cada RP, vínculos SSO y modelo local usan la misma conexión |
| Esquema SSO | Inventario de migraciones 1/1/0, historial y dry-run revisados. En instalaciones previas: adaptar sólo tablas propias mediante procedimiento específico respaldado y ensayado; no usar `migrate:fresh`, borrar historial ni tocar `users` |
| Auth del anfitrión | Guard/modelo correctos; `sso.local` en login/registro/reset/logout nativos y `auth` + `sso.session` en recursos. Revisar rutas efectivas con cachés y toda integración Breeze/Fortify u otra que exista |
| Sesiones | Driver de servidor, cookies Secure/HttpOnly/host-only/Lax y almacén protegido. Elegir `sso.session:strict` para el piloto; evaluar modo acotado aparte si el destino lo necesita |
| Red de salida | URLs remotas exactas autorizadas, redirects servidor-a-servidor desactivados y DNS/egreso bajo control. El endpoint privado `/oidc/session` es una extensión del proyecto: no presumir compatibilidad con cualquier OP externo |
| Claves | Material privado fuera del webroot, permisos restringidos, almacén de secretos, plan de rotación con convivencia de claves públicas y copia protegida del estado de prueba |
| Workers | Entrega de logout supervisada, scheduler de retención explícito, relojes sincronizados; medir desfase y registrar pausas. Los eventos de auditoría del anfitrión no equivalen a una outbox durable de auditoría |
| Capacidad | Presupuesto de CPU/RAM/DB/sesiones, métricas por instancia y límites de conexiones. Calcular tráfico del modo strict y NAT antes de elegir throttling; el default 120/min/IP no dimensiona automáticamente el destino |
| Preservación | Línea base privada del esquema y atributos de cuentas de prueba/ACL; comparación antes/después. No exportar hashes de contraseña: usar comprobaciones o HMAC privados, con claves fuera del informe |

Los backups y ensayos de restauración quedan limitados al estado SSO/Passport/sesiones
del espacio de pruebas; no restaurar ni sobrescribir `users`. Una copia antigua no puede
reabrir sesiones revocadas: restaurar aislado, invalidar los SID/dispositivos afectados
y comprobar rechazo antes de reabrir el acceso. El procedimiento debe ensayarse en G1.

## 4. Secuencia y puertas de salida

| Puerta | Trabajo | Evidencia necesaria para avanzar |
| --- | --- | --- |
| G0 — definición | Completar entorno, alcance, roles, cuentas, umbrales y canal privado | Ficha completa; capacidad de parada y restricciones verificadas |
| G1 — instalación reproducible | Fijar instantánea, instalar en staging, revisar esquema propio, vincular cuentas, instrumentar y ensayar recuperación aislada | Huellas concordantes, doctores aprobados, cachés/reinicios verificados y línea base de cuentas intacta |
| G2 — controles de entrada | Calidad sobre esa instantánea, flujos funcionales, negativos y revisión inicial de amenazas | Todas las pruebas obligatorias aplicables aprobadas; ningún bloqueo de identidad, credenciales o exposición antes de carga prolongada |
| G3 — operación externa | Navegadores físicos/comerciales, carga distribuida, observación de 48 h y fallos autorizados | Informes por perfil, intervalos de fallos explícitos, métricas y recuperación sin alterar cuentas |
| G4 — revisión independiente | Reproducción por el revisor, lectura de código/configuración, pruebas dirigidas y retest de correcciones | Cobertura trazable, informe firmado/identificado por el revisor y hallazgos resueltos según sección 7 |
| G5 — cierre | Comparar integridad, revisar excepciones no bloqueantes, retirar accesos temporales y detener el staging cuando corresponda | Acta conjunta de operador y revisor, artefactos redactados, limitaciones y decisión por destino |

G4 puede comenzar sobre la instantánea fijada en G1 y aportar bloqueos a G2. Una
corrección cambia la instantánea: registrar el diff, pasar calidad y repetir los casos
afectados, incluidos los operativos cuando cambie su comportamiento o entorno.

## 5. Matriz de pruebas obligatorias

Todos los casos siguientes tienen estado inicial **PENDIENTE**. Cada uno debe producir
pasos, perfil, resultado esperado/observado, timestamps y artefactos privados/redactados.

| ID | Prueba | Criterio de aceptación |
| --- | --- | --- |
| P01 | Calidad e inventario | Pint global, PHPUnit y grupo normativo propio sin fallos/saltos injustificados; Larastan sin baseline; manifiestos/plataforma válidos y audit revisado. Artefactos ligados a la instantánea |
| P02 | Instalación y preservación | Una migración servidor y una cliente; ninguna en contracts. Sólo esquema SSO revisado; `users`, hash, recaller, ID y ACL idénticos al inicio |
| P03 | Auth local con OP inaccesible | Login local funciona sin tablas/HTTP SSO necesarios para ese modo; endpoints SSO 404. Usar las cuentas preexistentes y el guard validado en G0 |
| P04 | Local → SSO → local con cachés | Reconstrucción de configuración/rutas y reinicio de procesos persistentes en todas las instancias. Sesiones anteriores no cruzan modos; nueva autenticación requerida. GET nativo redirige con SSO y POST con CSRF válido devuelve 403 |
| P05 | SSO y ACL en dos RP | Una entrada de credenciales centrales, mismo `iss/sub`, cuentas locales pre-vinculadas y permisos diferentes en A/B. Ningún cambio de perfil ni privilegios por claims |
| P06 | Vinculación y propiedad | Sin enlace por correo, reasignación implícita ni alta automática; vínculo explícito idempotente. Otra cuenta o clase de modelo no reutiliza la sesión |
| P07 | Code/PKCE y errores | Replay, verifier incorrecto, downgrade, redirect no registrado, cliente equivocado y parámetros duplicados rechazados; ningún token/acceso concedido. S256 obligatorio en los RP del piloto |
| P08 | Validación RP | Firma/algoritmo/kid, issuer, aud/azp, nonce/state, tiempos, `at_hash`, `max_age` y `sub` de UserInfo inválidos impiden login. Probar con respuestas controladas por el revisor |
| P09 | Navegadores y cookies | Repetir recorridos en Chrome, Edge y Firefox de escritorio; Safari en macOS e iOS y Chrome en Android físicos. Registrar versiones/OS/políticas y ejecutar todos los motores que G0 incluya. TLS estricto, cookies separadas; iframe cross-site no autentica con Lax |
| P10 | CSRF y fijación de sesión | POST de login/dispositivos/logout sin CSRF no muta estado; sesión se regenera al autenticar; query/callback/hint no aparecen en logs ni destinos no registrados |
| P11 | Recordarme y dispositivos | Rotación de un uso, replay, aislamiento entre dispositivos, vencimiento absoluto y confirmación reciente. Restaurar cookie no satisface autenticación fresca. Expiraciones largas se prueban además en perfil acelerado identificado, separado del perfil de duración real |
| P12 | Logout y revocación | Confirmación/cancelación, hint válido/inválido/ausente y destinos registrados. Cierre en A deniega B dentro del presupuesto acordado; no revoca otra identidad o dispositivo fuera del alcance |
| P13 | OP caído/lento y red | Caída HTTP 60 s, pérdida TCP silenciosa/timeout, DNS o TLS inválido, según autorización G0. Acceso SSO falla cerrado; nunca activa Auth local. Separar peticiones ya autorizadas de nuevas comprobaciones |
| P14 | Back-channel y worker | Pausa 120 s, HTTP 503 del RP, duplicados, reordenación y muerte del worker con reserva. Entregas no se pierden, duplicados son idempotentes y la reserva se recupera; medir drenaje/edad del pendiente |
| P15 | SQL y concurrencia del destino | Confirmar motor/aislamiento reales y ejecutar carreras de vínculo, código, Recordarme y outbox. Sin doble consumo, usuarios duplicados ni eventos de rollback; reintentos exteriores completos cuando el anfitrión abre transacciones |
| P16 | Proxy, SSRF y redirecciones | Cabeceras host/proto manipuladas no cambian origen, issuer o callback; URL no autorizada y redirección servidor-a-servidor rechazadas. Pruebas DNS/egreso sólo dentro del laboratorio autorizado |
| P17 | Claves y despliegue | Rotar clave OIDC conservando verificación de tokens aún válidos; retirar antigua según TTL/retención. Reinicio/rolling deploy si hay varias instancias no mezcla configuración ni resucita sesiones |
| P18 | Retención y restauración | Pruning no borra usuarios, vínculos ni entregas pendientes indebidamente. Restauración aislada no permite reutilizar acceso revocado ni cambia `users`; validar antes de habilitar tráfico |
| P19 | Harness OIDC oficial | Reproducir los cinco planes en el entorno controlado del revisor sobre la instantánea. Cero FAILED/WARNING sin resolver; cada REVIEW verificado y cada SKIPPED justificado por capacidades. Mantener estados oficiales y no declarar certificación |
| P20 | Límites y observabilidad | 429 al exceder límites pactados; tráfico nominal no queda bloqueado por una mala dimensión de NAT/strict. Alertas de OP/worker, backlog, DB y disco funcionan; no registrar secretos ni datos personales innecesarios |
| P21 | Carga y continuidad | Ejecutar los tramos de la sección 6; conservar percentiles, errores, recursos, colas y eventos de fallos por perfil/instancia; cuentas y ACL intactas al cierre |

La lista de dispositivos de P09 es el alcance propuesto. Cualquier omisión debe quedar
acordada en G0 y limita explícitamente el cierre; si un dispositivo forma parte del
destino declarado, su ausencia bloquea la aceptación de ese destino.
Los resets de contraseña y la mutación de `is_active` se evalúan fuera del destino,
mediante fixtures efímeros o revisión de la integración; no se ejecutan sobre sus cuentas.
En ese espacio separado, el revisor debe repetir también el alta greenfield, el registro
central y los contratos `RegistersClients`/`ManagesClients`: autorización del CRUD,
lecturas sin secretos, validación, rollback, edición parcial, revocación y carreras
con emisión. Debe conservar cuentas existentes y el acceso a otras aplicaciones.
Esto valida las APIs actuales sin cambiar la restricción de cuentas del staging.

P19 usa un fixture separado del revisor sobre el código candidato. Si los planes oficiales
necesitan la excepción sin PKCE ya documentada para dos clientes confidenciales del
harness, registrarla sólo en ese fixture y esos clientes; no extenderla a los RP del piloto.
Registrar todas las diferencias de configuración. Esa reproducción no acredita por sí
sola la configuración del staging externo, que se verifica con los demás casos.

## 6. Carga, medición y umbrales propuestos

Repetir por cada perfil declarado del destino. Usar dos generadores externos, cookies
independientes y cuentas sintéticas suficientes ya vinculadas; no cambiar ACL durante la
campaña. Registrar IP/NAT observada, calentamiento y perfil exacto de tasas. No enviar
tráfico a hosts ajenos. No reutilizar directamente los scripts de fixtures contra el destino:
sus guardas `/app` y laboratorios desechables son deliberadas; adaptar un harness específico.

| Tramo | Propuesta inicial |
| --- | --- |
| Calentamiento | 15 min; registrar métricas y excluirlo sólo de los percentiles de régimen estable |
| Referencia | 30 min / 8 usuarios HTTP, comparable con la campaña local; sin pretender igualar sus latencias |
| Carga objetivo | 2 h / 20 usuarios concurrentes, distribuidos entre dos generadores, pausa inicial 2 s por ciclo; ajustar a la demanda concreta en G0 |
| Continuidad | 48 h / 5 usuarios concurrentes, pausa inicial 5 s; atravesar cambios de día, caducidades y ciclos de worker/scheduler |
| Fallos | Inyectar P13/P14 en ventanas identificadas después de un tramo estable; no superponer fallos en el primer ensayo. Recuperar y observar al menos 30 min |

| Métrica | Umbral propuesto para ratificar en G0 |
| --- | --- |
| Seguridad/integridad | Cero accesos indebidos, secretos expuestos o cambios en cuentas/ACL; cero tolerancia, independientemente de tasas |
| Casos obligatorios | 100 % de casos aplicables aprobados; ningún caso pendiente o salto sin justificación |
| Error no esperado en carga nominal | Menor o igual a 0,1 % por perfil y tramo, con cada error clasificado; ninguna regresión funcional sin resolver |
| Recurso protegido | p95 ≤ 500 ms, medido de extremo a extremo desde el generador e incluyendo comprobación strict |
| Flujo SSO automatizado | p95 ≤ 3 s desde envío automatizado de credenciales/autorización hasta callback completado, excluyendo tiempo humano |
| Revocación | Medir presupuesto `B = intervalo de caché + timeout HTTP + 2 s` de tolerancia instrumental; con strict/timeout 3 s, objetivo ≤ 5 s en procesos activos. Registrar carreras de solicitudes ya en curso; no prometer tiempo real bajo pausas de proceso |
| Recuperación del OP | Salud y flujo autenticado recuperados en ≤ 120 s tras retirar el fallo, sin fallback local |
| Drenaje tras pausa de worker | Cero pendientes de la cohorte de prueba en ≤ 120 s desde reanudación, con RP disponibles |
| Fallos de entrega/reintentos | Entrega en el primer ciclo elegible tras recuperación, considerando `next_attempt`, reserva y capacidad pactada. No exigir drenaje inmediato si el backoff aún no vence; ninguna entrega descartada ni vencida sin alarma |
| Recursos | Sin OOM, reinicios involuntarios, agotamiento de conexiones o crecimiento sostenido sin estabilización; presupuesto de CPU/RAM/DB y tendencia máxima fijados en G0 |

Los 401/403/419/429/503 de negativos o fallos sólo son esperados cuando el caso y su
ventana los definan previamente. Se cuentan y presentan por separado, sin borrarlos del
registro. Reportar tamaño de muestra y p95/p99 por operación; no mezclar login, consultas
protegidas y fallos en un único promedio ni reutilizar el máximo p95 por worker como p95 global.
La sesión ya autorizada antes de una revocación no se cancela retroactivamente.

## 7. Encargo y cierre de la revisión independiente

El revisor debe poder reproducir la instalación y aportar evidencia propia, sin depender
sólo de los informes de los autores. Recibe acceso limitado al staging y una copia privada
del código/lockfile; los secretos se entregan por un canal separado y se revocan al cierre.
El canal privado de reportes se define y prueba con el operador: no se inventa una dirección
ni se presupone que GitHub Private Vulnerability Reporting ya esté habilitado.

Cobertura de lectura y pruebas dirigidas:

| Área | Foco de revisión |
| --- | --- |
| Protocolo y RP | P07/P08/P19, autenticación de cliente, errores, emisión/validación JWT, confianza en discovery/JWKS y rotación |
| Integración del anfitrión | Rutas efectivas y cachés, listeners de Auth, guards, rehash/recaller, Gates/Policies, permisos de operadores de vínculos y gestión de clientes; registro, recuperación y alta greenfield en fixtures separados |
| Persistencia | Restricciones/colaciones, propiedad del vínculo, aislamiento, reintentos, callbacks después de commit, reservas e idempotencia |
| Superficie HTTP | CSRF, cookies, middleware, escape de vistas, límites de entrada, SSRF, proxies, fugas en logs y manejo de errores |
| Operación y suministro | Instaladores/doctor, permisos de claves, secretos, lockfile/advisories, retención, despliegue y recuperación sin reabrir sesiones |

Entregables del revisor: alcance y exclusiones firmados/identificados, instantánea y
entorno reproducibles, matriz amenaza → componente → caso → evidencia, informe ejecutivo
y técnico, hallazgos con pasos/impacto/precondiciones, y retest por hallazgo corregido.
Usar ASVS como catálogo de requisitos aplicables, fijando versión y referencias de
controles en la matriz; no declarar cumplimiento completo de un nivel sin evaluarlo.

| Hallazgo | Tratamiento para G5 |
| --- | --- |
| Acceso indebido, fuga de secretos o violación de las restricciones de cuentas/migraciones | Parada inmediata y bloqueo de cierre, cualquiera que sea la etiqueta de severidad |
| Crítico/alto: toma de cuenta, cruce de identidad/ACL, replay explotable o revocación eludible | Cero abiertos; corregir y retest independiente antes de reanudar/cerrar |
| Medio | Corregir; excepcionalmente, riesgo documentado con mitigación, responsable y fecha, aceptado por operador y revisor. No puede dispensar un criterio obligatorio ni una restricción de la campaña |
| Bajo/informativo | Registrar, priorizar y asignar responsable; no ocultarlo en el resumen |

Ante un bloqueo: detener generadores y acceso al staging, conservar evidencia privada,
revocar sesiones/dispositivos SSO y secretos exclusivos del piloto según el procedimiento
del operador. No cambiar contraseñas ni restaurar `users`. Volver a Auth local es una
decisión operativa explícita, con cachés/reinicio y nueva autenticación, nunca un failover
automático. Si no puede preservarse ese límite, mantener el piloto detenido.

## 8. Evidencias y decisión final

Crear un expediente privado por campaña: IDs P01–P21 y estado, fuentes/lock/imágenes,
configuración sin valores secretos, versiones de navegadores/dispositivos, UTC y relojes,
JUnit/JSON, métricas de las dos fuentes de carga, eventos de fallo/recuperación, colas,
integridad de cuentas y acta de retest. Guardar también los intentos fallidos.

El registro de hallazgos contiene ID, severidad, caso vinculado, reproducción, impacto,
responsable, revisión correctora y evidencia de retest. El resumen compartible contiene
resultados y hashes; excluye tokens, códigos, cookies, client secrets, contraseñas,
hashes de contraseñas, cabeceras y cuerpos sensibles. Retención/custodia se fijan en G0.

G5 requiere conjuntamente: criterios de G0 satisfechos, P01–P21 aplicables cerrados,
cuentas/ACL intactas, hallazgos bloqueantes resueltos con retest y firma/identificación
del operador y revisor. El acta debe limitarse a las versiones, topología y dispositivos
ensayados. No autoriza producción general, certificación ni publicación de versiones.

## Referencias del plan

El perfil y los negativos se trazan a [OIDC Core 1.0, errata 2](https://openid.net/specs/openid-connect-core-1_0.html)
y a las recomendaciones de seguridad de [OAuth 2.0, RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html).
La revisión del código usa [OWASP ASVS](https://asvs.owasp.org/) como catálogo, con versión
fijada al abrir el encargo. Estas referencias orientan cobertura; los umbrales operativos
de este documento son propuestas propias del piloto.

Las pruebas de conformidad, la revisión independiente y el trámite de certificación son
actividades distintas. [OpenID Foundation describe su proceso de certificación](https://openid.net/certification/);
este plan contempla pruebas y revisión, sin iniciar ese trámite ni publicar resultados.
Guías del repositorio: [despliegue](DEPLOYMENT.md), [sesiones](SESSIONS.md),
[modelo de seguridad](SECURITY-MODEL.md), [cliente](INSTALL-CLIENT.md) y
[concurrencia/retención](DATABASE-CONCURRENCY.md).
