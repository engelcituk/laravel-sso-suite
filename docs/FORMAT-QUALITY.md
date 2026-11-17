# Cierre de formato y calidad

17 de noviembre de 2026, Cancún. Validación local del workspace sobre
`5616b3688106a6a64b3c11c1f687d33e258770f8`. No se publicaron versiones ni se activó
una nueva CI remota. [Resumen estructurado sin secretos](../conformance/format-quality-results.json).

## Corrección verificada

Pint completo, con una caché nueva, reprodujo exactamente los **37 archivos** señalados
en la preparación del piloto. Se guardó una copia de cada original y se aplicó Pint
exclusivamente a esos caminos. Una segunda ejecución global, también con caché nueva,
terminó **aprobada, con cero archivos señalados**.

La comparación byte a byte, normalizando CRLF/LF, comprobó que **los 37 cambios son
exclusivamente finales de línea**. Además se compararon los tokens PHP con `TOKEN_PARSE`,
omitiendo whitespace y normalizando whitespace de etiquetas/finales de línea de comentarios:
los tokens restantes y los literales de cadena permanecieron iguales. El JSON conserva
los caminos, SHA256 antes/después y la huella de tokens de los 37 archivos.

No se cambiaron condiciones, consultas, atributos de usuarios, contraseñas ni definiciones
de esquema. La única migración del cliente recibió el mismo ajuste de formato; su nombre
y sus tokens permanecen iguales. Servidor conserva una migración, cliente una y contracts
ninguna. No se creó ninguna migración incremental ni se ejecutaron migraciones en tus bases.

Con `core.autocrlf=true`, Git puede normalizar estas diferencias de finales de línea al
comparar contenido. Los hashes de archivos y la ejecución de Pint verifican el cambio
físico en el checkout Windows.

## Controles ejecutados

| Control | Resultado |
| --- | --- |
| Pint global, caché nueva | Aprobado; 37 archivos señalados antes, 0 después |
| PHP 8.3.36 / PHPUnit 12.5.44 | 230 pruebas / 1076 aserciones; sin fallos, errores ni saltos |
| PHP 8.4.27 / PHPUnit 12.5.44 | 230 / 1076; sin fallos, errores ni saltos |
| PHP 8.5.12 / PHPUnit 12.5.44 | 230 / 1076; sin fallos, errores ni saltos |
| Grupo normativo propio, PHP 8.3.36 | 106 / 414; aprobado; incluido en la suite general, no sumable como casos distintos |
| Larastan nivel 5, sin baseline | Aprobado, sin errores |
| Siete manifiestos Composer | `validate --strict` aprobado; paquetes/ejemplos con `--no-check-lock` |
| Requisitos de plataforma instalados | Aprobados en PHP 8.3 |
| Composer audit del lockfile | Salida 0; ningún aviso conocido ni paquete abandonado en esta consulta |
| Integridad del lockfile | SHA256 idéntico al inicial |
| Integridad de diez archivos protegidos de ejemplos | Bases y secretos con contenido idéntico al inicial |

PHPUnit y Larastan se ejecutaron en serie para evitar carreras de los manifiestos Testbench
en Windows. PHP 8.5 utilizó el `php.ini` aislado existente; no se modificó la instalación
global. Los tests usaron fixtures efímeros, incluidos los casos de migración y conservación
de credenciales; no se ejecutó setup/E2E contra las bases de ejemplos.
Composer audit consulta avisos de dependencias y no sustituye una revisión del código propio.

Logs, JUnit, originales y comparaciones: `.runtime/pint-quality-20261117/`, ignorado por Git.
El resumen versionado selecciona resultados y hashes, sin datos ni secretos de usuarios.

## Alcance de la evidencia anterior

Los informes de [preparación del piloto](PILOT-PREPARATION.md), OIDC oficial, navegadores
y carga conservan sus contenidos y huellas originales. El formato cambia hashes de bytes,
aunque los tokens PHP sean equivalentes: no se reetiquetan las campañas anteriores como
nuevas ejecuciones sobre este checkout. Tampoco se repitió la matriz SQL en esta fase.
La conformidad repetida aquí es el grupo PHPUnit propio, no el harness oficial OIDF.

Queda cerrado el hallazgo de Pint global. La siguiente fase sigue siendo el piloto externo
en un destino concreto y la revisión independiente, sin autorización para publicar versiones.
