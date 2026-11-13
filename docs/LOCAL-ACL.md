# ACL local con usuarios Eloquent

Esta guía sustituye las menciones contradictorias de ACL sin users del prompt original.
Cada cliente conserva su propia tabla users sin credenciales, decisión confirmada por el
usuario. La clave de identidad es issuer/subject y las relaciones usan users.id.

`examples/shared/client-model.php` contiene ReportPolicy. Un Gate llama a esa Policy y
consulta `report_permissions` en la base local. Aprovisionar una identidad no agrega permisos.
Después de acceder, concede un permiso sólo en Application One:

```sh
php examples/app-one/artisan example:grant-report SUJETO_CENTRAL
```

`/me` muestra el sujeto y el resultado del Gate. `/reports` devuelve 403 sin autorización.
Application Two seguirá denegando aunque la identidad sea la misma. La prueba HTTP E2E
comprueba ese aislamiento con tres bases diferentes.

Puedes sustituir el ejemplo por tus tablas roles/permissions o un paquete ACL opcional.
El OP no conoce ni emite roles de negocio. No confíes en claims externos para conceder
administrador; la decisión corresponde a la política local explícita.
