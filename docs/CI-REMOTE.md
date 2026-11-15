# Matriz CI remota: resultados reales

Verificación del 2026-11-14 en Cancún, **2026-11-15 03:49:20–03:51:41 UTC**.
El [run 43674055824, intento 1](https://github.com/engelcituk/laravel-sso-suite/actions/runs/43674055824)
terminó con **seis trabajos aprobados y cero advertencias del runner**. Tras la autorización
del usuario se subió únicamente la corrección de checkout a `codex/ci-checkout-node24`;
la creación de esa rama activó el workflow de push. Se descargaron y revisaron sus seis logs.

Commit probado: `6221d0601e4c116afcc525f51db66e17151796cf`, workflow `quality`,
ejecución número 9. Respecto a `7e5640c`, sólo cambia tres líneas del workflow.
Blob Git del workflow probado: `ab84c68cfa2a41eda4b63a371dc12597101f3f31`.
La documentación de resultados se incorpora después; no se atribuye al commit probado.

## Resultados por trabajo

| Trabajo / ID | Entorno real | Resultado |
| --- | --- | --- |
| test (8.3) / 132690193745 | PHP 8.3.36 | 209 tests / 982 aserciones; 106 / 414 normativos |
| test (8.4) / 132690193714 | PHP 8.4.27 | 209 / 982; 106 / 414 normativos |
| test (8.5) / 132690193873 | PHP 8.5.12 | 209 / 982; 106 / 414 normativos |
| package-manifests / 132690193722 | PHP 8.3.36 | seis manifiestos de paquetes/ejemplos válidos |
| database-concurrency (mysql) / 132690193850 | PHP 8.3.36, MySQL 8.4.13 | 224 / 1055, sin fallos ni saltos |
| database-concurrency (pgsql) / 132690193622 | PHP 8.3.36, PostgreSQL 18.7 | 224 / 1053, sin fallos ni saltos |

Todos los pasos terminaron en `success`. Cada trabajo PHP pasó Composer validate/install,
Pint (**137 archivos en este checkout remoto**), Larastan sin errores, auditoría de
dependencias sin avisos conocidos en esa consulta, instalación de ejemplos y E2E.
El manifiesto raíz también se validó en cada trabajo PHP: siete manifiestos distintos
en total. Los 145 archivos del informe local de despliegue son un conteo histórico de
otra ejecución; no se atribuyen a esta CI.

E2E comprobó SSO y aislamiento de ACL, recuperación/rotación de Recordarme, CSRF,
confirmación de contraseña, revocación por dispositivo, logout global y tres entregas
back-channel. Pasaron el doctor OP y ambos doctores RP con discovery/JWKS reales.
La suite SQL incluye las catorce carreras y comprueba REPEATABLE READ en MySQL y
READ COMMITTED en PostgreSQL; las versiones de los motores se verificaron además en
sus logs de arranque. Los JSON de entorno SQL no se subieron como artifacts y no se
presentan como descargados de GitHub.

Los runners fueron GitHub-hosted `ubuntu-latest`, realmente **Ubuntu 26.04.1 LTS**,
agente **2.338.2**. Imagen `20261111.196.1` en los seis trabajos.
No se infieren versiones del nombre mutable `ubuntu-latest`.

## Advertencia y corrección

El [run anterior 43673080776](https://github.com/engelcituk/laravel-sso-suite/actions/runs/43673080776),
commit `7e5640c`, pasó con **seis advertencias del runner**, una por trabajo:
`actions/checkout@v4` declara Node.js 20, obsoleto, y GitHub lo forzó a Node.js 24.
La nueva ejecución con la corrección tiene **cero advertencias del runner y cero fallos**.
Los mensajes de inicialización de las bases desechables tampoco representan errores
del protocolo ni pruebas omitidas.

Se actualizaron las tres referencias del workflow a
`actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1` (**v7.0.1**), fijado al
commit del tag oficial. Su [metadato oficial](https://github.com/actions/checkout/blob/3d3c42e5aac5ba805825da76410c181273ba90b1/action.yml)
declara `node24`. El [README oficial](https://github.com/actions/checkout/blob/3d3c42e5aac5ba805825da76410c181273ba90b1/README.md)
indica el mínimo de runner 2.327.1 para Node.js 24; el observado supera ese mínimo.
Se mantienen los disparadores push/PR y permisos `contents: read`.

Validación local del workflow modificado: **actionlint 1.7.12, salida 0**, sin diagnósticos;
`git diff --check` aprobado. El ZIP Windows del validador se contrastó con las sumas
publicadas en su [release oficial](https://github.com/rhysd/actionlint/releases/tag/v1.7.12).
No se modificó PHP, dependencias ni esquema: servidor y cliente conservan una migración
inicial cada uno; contracts no tiene migraciones.

**La corrección quedó validada remotamente.** Los seis logs confirman la descarga del SHA
fijado de checkout, todos sus pasos terminaron en success y no registraron `##[warning]`.
No hizo falta corregir PHP ni repetir trabajos fallidos. El run anterior conserva sus
advertencias históricas; no se alteraron sus resultados.
Se subieron la corrección y la documentación a la rama autorizada. `main` no se modificó;
no se creó PR, tag, release ni publicación en Packagist.

## Evidencia y reproducción

[Resumen estructurado sin secretos](../conformance/ci-results.json): commit, run, enlaces
a trabajos, versiones, conteos, huellas SHA-256 de los logs descargados y comparación
con la ejecución anterior. Los logs completos permanecen ignorados en
`.runtime/ci-remote/run-43674055824/` y `run-43673080776/`; las huellas corresponden a esos archivos decodificados,
no a un ZIP original de GitHub. No se versionan credenciales efímeras ni logs crudos.

Para repetir el commit probado, usa **Re-run all jobs** en ese run de GitHub Actions.
Para probar cambios futuros, el workflow se activa con push o pull request; no define
`workflow_dispatch`. Subir cambios para CI no autoriza publicar versiones.

## Alcance restante

Los 106 casos normativos son la suite propia: esta CI **no ejecutó el harness oficial
OpenID Foundation**, ni Playwright cross-site ni la campaña de carga de 30 minutos.
Sus resultados locales previos se conservan en los informes correspondientes.
La matriz PHP comparte el lockfile; no evalúa dependencias alternativas. La matriz SQL
no cubre todos los motores, versiones o aislamientos posibles.

La matriz remota y la corrección de checkout del commit indicado quedan verificadas.
Esta corrección no tiene validaciones pendientes. Continúan fuera de lo
validado el piloto externo, navegadores comerciales/móviles físicos, carga distribuida
o multijornada y auditoría/certificación independiente. Nada de ello se declara aprobado.
