# Contribuir

PHP 8.3+, Composer 2 y Git. Ejecuta composer install y las verificaciones del README.
Incluye una prueba del comportamiento nuevo y pruebas negativas para cambios de protocolo.
No copies implementaciones OIDC ajenas, no modifiques vendor y no agregues dependencias
de interfaz/ACL al núcleo. Mantén independientes los paquetes cliente y servidor.

Documenta cualquier API Bridge no garantizada y comprueba el intercambio real de códigos.
No añadas claims, grants o metadatos discovery que no estén implementados y probados.
No suprimas fallos de análisis con baseline. No uses fixtures con secretos de producción.

Las PR deben explicar problema, comportamiento resultante, validación y límites. Las fases
se cierran sólo con evidencia. Sigue SemVer cuando exista la primera publicación; antes de
1.0 las APIs siguen experimentales y los cambios deben constar en CHANGELOG. No publicar
tags, paquetes o releases sin autorización de los mantenedores.
