# Etapa 3 — Primer tramo de T06

20/10/2026. Implementado y probado localmente; no publicado. Las comprobaciones externas de etapa 2 quedan pendientes por decisión expresa del usuario y no bloquean este trabajo. Esta entrega no cierra T06 ni T07.

## Centro en Flutter

Perfil incorpora «Privacidad y mis datos», con la paleta y componentes existentes. Incluye preferencias, preparación de JSON y solicitudes propias. El JSON se prepara en memoria y solo se entrega al mecanismo de guardar/compartir tras otra acción explícita. La advertencia previa identifica fotos/GPS; no se comparte automáticamente. Share Plus puede usar archivos temporales administrados por plataforma: no se promete borrado de copias externas ni protección del archivo que el usuario decida compartir.

Las credenciales, roles y datos de otra persona no se aceptan desde los formularios. Cambio de cuenta limpia archivo preparado, información de pantalla y detalle de solicitud. Cada petición está asociada al UID de apertura; el ensamblador verifica el UID antes/después de cada página y descarta todo ante errores. No produce un archivo parcial presentado como completo.

## Preferencias

GET `/api/me/privacy` devuelve preferencias, disponibilidad del canal y aviso de exportación. No se publicó una política jurídica ficticia: `politicaPublicada=false`.

PATCH `/api/me/preferences` admite exclusivamente booleanos `recordatoriosCuidados` y `avisosEquipo`. Rechaza claves extra, incluido `premium`/`userId`. Actualización y evento `consentEvents` comparten commit condicional del perfil. Si se repite un estado ya aplicado no genera otro evento. Son preferencias de avisos del servicio, **no consentimiento universal ni permiso para publicidad**. Se conservan los defaults de avisos existentes; valores almacenados inválidos no se interpretan como permiso.

La UI usa el valor confirmado por servidor. Al desactivar cuidados ejecuta `clear()` local directamente, sin un segundo GET; al habilitar sincroniza nuevamente. El programador de recordatorios y el envío manual consultan la preferencia/cuenta. También se comprueba antes de mostrar un push en primer plano. Otros dispositivos actualizan sus avisos locales cuando abren/sincronizan la app; no se pueden retirar notificaciones que el proveedor ya aceptó o el sistema operativo ya presentó. No es revocación de permisos del sistema operativo.

## Exportación propia

POST `/api/me/exports`, con autenticación reciente, crea una sesión de 15 minutos y devuelve 202 con ID aleatorio, cursor aleatorio y manifiesto. GET `/api/me/exports/{id}?cursor=...` devuelve una página autenticada. DELETE en esa misma sesión cancela. No hay URL pública de descarga.

Los cursores públicos son aleatorios; las posiciones quedan en `exportSessions`, sin copiar fotos/GPS o documentos exportados. Se guarda una posición anterior para reintentar la última página ante respuesta perdida. Tanto la página nueva como su replay verifican mediante commit las versiones del perfil activo y la sesión antes de devolver datos. Cursor viejo/desconocido se rechaza. Las sesiones canceladas/vencidas responden 410 y otro UID recibe 404. La verificación de revocación de Firebase sigue siendo la del middleware existente.

Una página procesa un documento o una categoría vacía, con límite de respuesta de 1,8 MB. Límite total de 3000 páginas y 64 MiB en memoria móvil. Una categoría vacía puede avanzar el cursor sin registros; no significa fin. Registros antiguos excesivos producen error y requieren asistencia, sin truncamiento. El servidor lee en vivo; el manifiesto no promete instantánea transaccional, y una modificación concurrente puede requerir regenerar el archivo.

Categorías actuales: perfil, mascotas, vacunas, medicamentos, alimentaciones, pesos, paseos, recordatorios, metadatos de dispositivos, notificaciones propias, solicitudes y eventos de preferencias. El perfil usa los datos del titular; recursos se filtran por `userId`. La proyección usa esquemas/allowlists. Dispositivos no incluyen tokens ni su ID legacy en claro; notificaciones excluyen identidad de administradores y datos de entrega internos. Se explican exclusiones en el manifiesto: credenciales/secretos, terceros, logs/configuración interna y datos de proveedores futuros.

También recorre el inventario legacy conocido bajo `users/{uid}`: mascotas y sus vacunas/medicamentos/alimentaciones/pesos/paseos, recordatorios y dispositivos. Incluye descendientes de padres ausentes. Una contradicción de propietario en legacy detiene la exportación; no expone esos datos ni los omite fingiendo completitud. Colecciones futuras deben añadirse explícitamente al inventario y proyecciones.

Las sesiones tienen caducidad de acceso, pero no se activó TTL ni se afirma purga física automática. Contienen UID/posiciones/fechas; retención y limpieza de metadata requieren procedimiento aprobado. El borrado existente se amplió para incluir sesiones/eventos/solicitudes propias, pero continúa siendo síncrono hasta T07.

## Solicitudes y contacto

POST `/api/me/privacy-requests` acepta `tipo`, `detalle` de hasta 4096 bytes y `solicitudId` estable. Tipos: acceso, corrección, oposición, bloqueo, anonimización y consulta. ID derivado de UID+solicitudId impide duplicar ante reintento. Reutilizarlo con otro contenido responde 409. GET colección y GET por ID muestran únicamente solicitudes propias, incluyendo estado. El titular no puede escribir su estado ni respuestas operativas. Los administradores de avisos no reciben un endpoint de lectura de solicitudes.

El usuario indicó `pawlife.support@gmail.com`: se configuró como contacto visible de Soporte PawLife, sin enviar correo ni cambiar permisos. Es un nombre de atención, no identificación jurídica del responsable legal. `PRIVACY_RESPONSIBLE` y `PRIVACY_CONTACT_EMAIL` pueden sustituir el contacto por entorno. La atención actual es por correo: el formulario de recepción/seguimiento queda deshabilitado y POST responde 503 `PRIVACY_REVIEW_UNAVAILABLE` hasta implementar/asignar el acceso específico y su procedimiento. Dar un correo no otorga acceso a los administradores de notificaciones. Las pruebas con recepción habilitada y `example.test` son fixtures; no se habilitó una cola desatendida por completar variables.

## Cuotas y comprobación

Por UID/hora: 60 cambios de preferencias, 10 solicitudes, 3 sesiones de exportación y 3000 lecturas de páginas. Se reservan antes del trabajo; fallos pueden consumir cuota. Las cuotas operativas no incluyen el contenido de las solicitudes.

PHP: `privacy_stage3_test.php` prueba allowlists, idempotencia, UID ajeno, varias páginas, categorías/legacy, padre ausente, exclusión de FCM/administrador, expiración, cancelación y carreras durante replay. Pruebas de notificaciones verifican que preferencias desactivadas impiden envíos.

Flutter: 14 pruebas nuevas de ensamblado, fallo sin archivo parcial, cambio de cuenta, bucle/final inválido, preferencias, contacto sin permisos y render 320/390/800 px con texto 100%/200%. Una prueba específica comprueba que desactivar cuidados cancela localmente aunque cualquier GET posterior falle. Render inspeccionado: `PawLife/build/test-artifacts/stage3-privacy.png` (fixture). Suite completa final: 73 pruebas aprobadas y análisis limpio. PHP y 3 pruebas JS del panel aprobadas.

Astra detectó y verificó correcciones de dos hallazgos P2: replay sin precondiciones y desactivación local dependiente de otro GET. No dejó hallazgos abiertos en el alcance revisado. No ejecutó Flutter ni comprobó producción; eso no certifica cumplimiento jurídico ni T07.

## Pendientes de etapa 3

- T06: cambio de correo con verificación/reauth y sincronización segura de identidad; información jurídica/versiones/retención; responsable y canal real; procedimiento y permisos específicos de atención manual. Validación real de descarga/compartir en Android/iOS/web y de preferencias en varios dispositivos.
- T07: borrado reanudable con tombstone, barrera en todos los escritores/workers, checkpoints, verificación final, Auth idempotente y vía web. El DELETE actual no se declaró durable ni se sustituyó por un proceso parcialmente implementado.
- Antes de distribuir el nuevo móvil, publicar primero la API correspondiente; el cliente no simula éxito si las rutas nuevas todavía no existen. No se hizo push, despliegue ni envío real en esta entrega.
