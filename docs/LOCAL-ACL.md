# ACL local con usuarios Eloquent

Cada cliente conserva su tabla users, credenciales y permisos. La identidad issuer/subject
se vincula en `sso_identities`; las relaciones de negocio siguen usando users.id.
El servidor central autentica; las decisiones de autorización pertenecen a cada cliente.

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
