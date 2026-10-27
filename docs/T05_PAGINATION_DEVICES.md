# T05 — Paginación y dispositivos

Implementado y probado localmente el 20/10/2026. No desplegado.

## Contrato de lectura

Las rutas de listado de ResourceController aceptan `pageSize` entre 1 y 100. Con este parámetro devuelven `{items, nextCursor}`; `nextCursor: null` indica fin. Por tamaño de respuesta, mascotas limita cada página a 4 documentos y paseos a 2; otros recursos, a 100. `limit` explícito puede reducir esa cantidad. No se devuelve un total ficticio. Las peticiones antiguas sin `pageSize` conservan su respuesta anterior.

El cursor público contiene solamente el ID del último documento. El servidor recupera sus valores de orden y verifica los filtros propios del usuario y de la mascota. El orden añade el ID como desempate para no omitir documentos con fechas iguales. Si el cursor desapareció, cambió de ámbito o resulta inválido se rechaza con 400: recargar desde el principio. No es una instantánea de una colección que se esté editando durante la lectura.

Flutter sigue las páginas manteniendo los filtros, comprueba que no cambió el UID y rechaza bucles, errores de página y respuestas inválidas. El límite defensivo es 250 páginas u 8 MiB de elementos acumulados: si se alcanza, informa un error; no presenta un listado truncado como completo. Un `limit` explícito se respeta. El historial de paseos conserva su contrato específico existente.

## Registro y revocación

- `POST /api/me/devices`: cuerpo `{token, plataforma}`. Alta 201 o actualización 200. El servidor decide el ID opaco y el propietario.
- `POST /api/me/devices/revoke`: cuerpo `{token}`. Devuelve 200 `{revocado: true}`, incluso si ya no existe el dispositivo propio.
- El móvil nuevo no envía tokens en URLs ni vuelve a las rutas antiguas cuando el servidor no reconoce estas rutas. Un 404 es un error de compatibilidad, también al revocar.
- Las rutas `/api/dispositivos` y sus IDs anteriores se conservan para clientes existentes. Esa compatibilidad no elimina tokens de sus URLs antiguas ni limpia registros históricos del proveedor.

El ID es `device-` seguido del SHA-256 del token, global por token. La asociación de un token que ya pertenece a otro UID se rechaza con `DEVICE_OWNERSHIP_CONFLICT`; no se lo reasigna silenciosamente. Renovar un token requiere una nueva alta.

La migración del documento antiguo al nuevo se hace en un solo commit, junto con la comprobación de versión del perfil y la reserva de cuota. Si cambia un documento, falla todo. Una respuesta perdida se puede reintentar usando el mismo ID, sin crear un segundo registro. Flutter reintenta hasta tres veces los conflictos de versión; no reintenta conflictos de propietario. No se afirma entrega exactamente una vez de notificaciones.

La cuota técnica de registro es de 60 escrituras exitosas por hora y UID. Su reserva tiene precondición y comparte el commit: una escritura rechazada no consume cuota. La revocación no está limitada por esta cuota. `operationQuotas` conserva un documento por usuario para este fin y se incluye en el borrado de cuenta existente. No se activó TTL ni una política legal de retención.

## Escrituras y permisos

Los PATCH de recursos usan la versión leída para evitar sobrescribir una actualización concurrente. Los commits usan operaciones REST `update/delete` y precondiciones; no usan `verify`, que no forma parte del contrato REST Write. El presupuesto es de 100 operaciones y 2 MiB por commit, además del control por documento.

Un conflicto de versión se transforma en 409, incluyendo `FAILED_PRECONDITION` que Firestore entrega como HTTP 400 para una escritura condicional. Un error 400 de consulta, por ejemplo índice faltante, conserva el 400. No se expone el mensaje privado del proveedor.

Referencias: [Write REST](https://firebase.google.com/docs/firestore/reference/rest/v1/Write), [commit REST](https://firebase.google.com/docs/firestore/reference/rest/v1/projects.databases.documents/commit).

## Publicación y límites de evidencia

Publicar primero la API y comprobar las rutas nuevas; después distribuir Flutter. Invertir el orden deja el registro/revocación del móvil nuevo sin endpoint compatible. No se publicaron cambios en esta etapa.

Las pruebas usan transporte Firestore simulado, no credenciales reales. Cubren aislamiento, orden estable, conflictos concurrentes, migración sin escrituras parciales, pérdida de respuesta, cuota y revocación. No comprueban IAM, índices desplegados, recepción FCM ni GPS físico. No sustituyen T07 de borrado durable ni completan seguridad integral de toda la aplicación.

Pendientes de T05: validación completa de imágenes compatible con producción, pruebas de restauración, procesamiento de trabajos largos y ampliar controles a los demás consumidores que los necesiten. Los listados antiguos sin paginación conservan sus límites anteriores y no se declaran escalables por este cambio.
