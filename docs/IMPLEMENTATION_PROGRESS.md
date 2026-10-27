# PawLife — Avance de implementación por etapas

Actualizado: 20/10/2026. Especificación: [PAWLIFE_IMPLEMENTATION_PLAN.md](PAWLIFE_IMPLEMENTATION_PLAN.md).

## Alcance de este corte

Implementación local de T01–T03 y primer tramo de T05. No se publicaron cambios ni se modificaron datos de producción. Bases del código: PawLife `d9d1b08`, apipaw `e475895`. Los cambios de Guillermo de GPS, captura de mapa y pestañas se conservan.

No confundir este corte con la finalización de toda la aplicación. La revisión de seguridad de Astra cubre estos cambios, no funcionalidades futuras ni configuración desplegada.

## Seguimiento completo

| Tarea | Estado | Siguiente paso concreto |
|---|---|---|
| T01 Paseos/PNG | Cambios implementados y pruebas locales aprobadas | GPS/compartir nativo en teléfono, permisos, atribución del mapa en captura real; fotografía convencional opcional sigue sin decisión |
| T02 Historial de peso | Implementado, pruebas de API simulada y widgets aprobadas | Persistencia en sandbox real; paginación si el volumen lo requiere en T05 |
| T03 Contraseña/Google/push | Cambio de contraseña, reauth guiada y cold start implementados y probados con dobles | Contraseña real sandbox, OAuth web real, FCM en dispositivo y cancelaciones de proveedor |
| T04 Inactividad | Pendiente por dependencia | Después de preferencias y jobs T05/T06; no generar avisos solo desde el móvil |
| T05 Seguridad/recuperación | Primer tramo implementado; **no cerrado** | Paginación/commits/jobs, validación completa de imágenes, rate limits restantes, migración de IDs de dispositivos y recuperación/backup |
| T06 Centro de privacidad/exportación | Pendiente | Base T05; responsable, contacto, bases y retención por confirmar; preparar con fixtures, sin política legal ficticia |
| T07 Eliminación reanudable/web | Pendiente | Mantener DELETE existente; añadir barrera de escritura, checkpoints y reintentos antes de cambiar contrato |
| T08 Moderación privada | Pendiente | Política/proveedor/costo/privacidad por decidir; no sustituir por lista de palabras |
| T09 Enviar a todos | Pendiente | Lotes backend duraderos; conservar selección y avisos actuales del panel |
| T10 Compras/Premium | Pendiente | Beneficios/productos/precios/cuentas/canales; sandbox y verificación backend. Astra revisará pagos antes de aceptar esta etapa |
| T11 Premium manual | Pendiente | Entitlement de fuentes separadas y permiso específico; no escribir flags libremente |
| T12 Idiomas | Pendiente | Español/inglés/portugués solo Flutter, después de preferencias |
| T13 Apariencia | Pendiente | Claro/oscuro/sistema; no rediseño general |
| T14 Calorías | Condicionado | Modelo y validación veterinaria; no calcular cifra provisional |
| T15 Integración/distribución | Pendiente | Pruebas sandbox/dispositivo, firma Android y recuperación; no usar firma debug como distribución |
| T16 Propuestas visuales | Pendiente, solo propuestas | Capturas actuales y maquetas separadas; no modificar diseño general de Flutter |

## Cambios de la primera etapa

### PNG y captura

- `TipoImagenPaseo` y campo opcional `imagenTipo` viajan desde WalkSession a API y Paseo. Valores `foto`, `mapa`, `desconocida`. Registros antiguos no se clasifican por intuición.
- `RouteScreen` conserva la captura del mapa y la marca como `mapa`; su botón usa icono de mapa. La imagen sigue disponible dentro del paseo.
- `WalkShareCard` y la precarga de `WalkShareScreen` usan la misma selección segura: foto explícita o foto de mascota; mapa/imagen desconocida del paseo no entra como avatar.
- El aviso aclara que se comparte la traza sin mapa de calles y que la forma puede revelar lugares. No se afirma anonimato.
- El filtro de Guillermo conserva sus umbrales y ahora descarta coordenadas no finitas/fuera de rango y precisión no válida. El foreground deja de mostrar latitud/longitud en la pantalla bloqueada.
- Se añadió atribución textual al área capturable del mapa. Queda verificar su visualización en el dispositivo y resolver enlace/atribución en las restantes vistas de mapas conforme al proveedor; no se declara auditoría cartográfica completa.

### Peso

- Acceso «Historial de peso» desde detalle, listado fechado con kg, alta y eliminación confirmada.
- Se reutiliza `fetchPesos/createPeso/deletePeso`; listado ordenado por fecha e ID. Alta admite coma decimal, exige valor positivo finito y no inventa un límite clínico por especie.
- El diálogo conserva ID/fecha/valor al reintentar. Tras 409, solo se reconoce como éxito el registro propio con igual valor y fecha; la fecha se normaliza a milisegundos, precisión del contrato API.
- Errores conservan el valor; un rechazo de validación permite corregirlo. Después de guardar/borrar y volver al detalle se actualiza el último peso.

### Autenticación y push

- `AuthService.reauthenticateForSensitiveAction` admite credencial de contraseña o Google según proveedor. Google reautentica al usuario existente; no reemplaza la sesión por otra cuenta.
- `changePassword` exige proveedor password, reautenticación y refresco de token; comprueba que el UID no haya cambiado en los puntos sensibles.
- Perfil ofrece cambio de contraseña a cuentas password y confirmación de identidad antes de eliminar. Su cliente API queda asociado al UID de apertura; completar el borrado no cierra una cuenta diferente que haya iniciado sesión mientras tanto.
- `getInitialMessage` se consume una vez y refresca exclusivamente los datos propios de la sesión vigente. No abre recursos ni muestra contenido privado de ese payload. Los callbacks de sesiones anteriores se descartan.
- La navegación directa a un recurso desde el push no se implementó: la interacción actual refresca los recordatorios propios, igual que el manejador previo de apertura en background.

## Primer tramo de T05

- `Request` ya tenía límite de body de 2 MiB: se conserva. Ahora además rechaza JSON malformado, raíz que no sea objeto y profundidad superior a 32. No convierte JSON inválido silenciosamente en `{}`.
- `DocumentBudget` impone un presupuesto conservador de 900 KiB sobre el JSON de campos REST de Firestore. Controla altas y fragmentos de PATCH en FirestoreClient y el documento completo combinado en ResourceController/Perfil. No se declara protección agregada de futuras rutas que no pasen por esos controles.
- Rutas GPS limitadas a 6000 puntos antes de convertirlos; exceso se rechaza expresamente y no se trunca. Son límites técnicos de almacenamiento/procesamiento, no recomendaciones de duración/distancia del paseo.
- Orden de consultas validado contra campos permitidos y metadatos `creadoEn/actualizadoEn`; se comprueban defaults de todos los schemas. Límites explícitos de consulta entre 1 y 3000. Lectura general sin paginación todavía pendiente; no se cambió silenciosamente a una lista parcial.
- `ApiError` da códigos estables e ID de correlación. Respuestas 5xx y logs omiten mensaje/trace privado; Firestore no devuelve el texto crudo del proveedor. El programador guarda código de fallo en lugar del mensaje de excepción.
- `REAUTHENTICATION_REQUIRED` conserva el 401 compatible. Flutter lo distingue y evita repetir automáticamente una operación sensible: refrescar token no sustituye reautenticar.

### Incompatibilidad de imágenes identificada

El PHP local tiene GD. La [documentación del runtime vercel-php](https://github.com/vercel-community/php) no permite asumir la misma extensión en producción: GD no aparece en su lista publicada. No se añadió un requisito GD obligatorio que pudiera bloquear las fotos al desplegar.

**Pendiente:** comprobar disponibilidad real en entorno autorizado o elegir procesamiento compatible para decode/reencode y metadatos. La validación base64 actual no constituye validación completa de imagen. Google Vision para moderación requiere habilitar billing; mantener la decisión de no facturar hasta autorización explícita. No confundir este bloqueo con la corrección ya realizada del PNG.

## Evidencia local

| Verificación | Resultado |
|---|---|
| `flutter test --no-pub --reporter expanded` | **42 pruebas aprobadas** con SDK Flutter 3.47.2 / Dart 3.13.2 |
| `flutter analyze --no-pub` | Sin incidencias, repetido después de integrar los códigos y manejo de reauth de ApiClient |
| `php tests/selftest.php` | 29 pruebas aprobadas, usando OPENSSL_CONF local |
| `php tests/security_regression.php` | Aprobado |
| `php tests/walk_notification_features.php` | Aprobado; incluye regresión de seguridad |
| `php tests/walk_image_metadata_test.php` | Aprobado |
| `php tests/input_privacy_limits_test.php` | Aprobado: JSON, profundidad, presupuesto, ruta, orden/defaults, redacción y reauth |
| `node --test tests/notification_batch_test.mjs` | 3 pruebas aprobadas; flujo de personas seleccionadas conservado |
| Renders Flutter | 320/390/800 px y texto 100%/200%, sin excepciones; inspección visual de peso, contraseña y PNG |
| `git diff --check` | Aprobado en ambos repositorios |

Las pruebas de permisos/cold start usan dobles y contenido sintético; no prueban entrega real FCM, OAuth real ni funcionamiento GPS exterior. Las capturas son renders del motor Flutter con fixtures, no fotografías de un teléfono.

Artefactos locales regenerables, ignorados por Git: `PawLife/build/test-artifacts/stage1-peso.png`, `stage1-password.png`, `walk-share.png`.

`flutter pub get` descargó dependencias ya declaradas por el último pull y actualizó cinco dependencias transitivas fijadas por el SDK local en pubspec.lock. No se agregaron dependencias directas. Verificar con el SDK acordado del equipo antes de publicar.

## Revisión independiente de Astra

Astra revisó privacidad/permisos del diff de etapa 1 y del primer tramo de T05. Hallazgos corregidos y comprobados:

1. La precisión microsegundos del peso no coincidía con la respuesta en milisegundos; corregido con prueba `.123456Z → .123Z`.
2. La allowlist inicial de orden excluía `actualizadoEn`, default de dispositivos; se incorporaron metadatos permitidos y pruebas de defaults.

No dejó hallazgos pendientes dentro de ese diff tras las correcciones. No evaluó compras inexistentes, producción ni el total de T05. Reservar una nueva revisión Astra para permisos/privacidad de T06–T07 y para pagos/entitlements de T10–T11.

## Segundo tramo de T05 — 20/10/2026

- Paginación conectada entre ResourceController, Firestore REST y los listados de Flutter, con filtros de propietario, desempate por ID, cursores validados y errores explícitos si cambia la sesión o falla una página. Compatibilidad conservada para peticiones antiguas sin paginación.
- Escrituras condicionales y commits atómicos implementados. Los PATCH de recursos rechazan conflictos de versión en vez de sobrescribir cambios concurrentes.
- Registro y revocación del móvil usan rutas fijas y token en el cuerpo. La migración del dispositivo antiguo, el documento nuevo, el perfil y la cuota comparten un commit. Un token de otro usuario no se reasigna.
- Cuota técnica de 60 registros exitosos por hora/UID, sin impedir revocación. No hay cambios de facturación ni infraestructura adicional.
- Corregido el falso éxito de revocación cuando el servidor responde 404: ahora se informa el fallo; la nueva ruta ya es idempotente con 200.
- Contrato, límites, compatibilidad y orden de publicación: [T05_PAGINATION_DEVICES.md](T05_PAGINATION_DEVICES.md).

### Evidencia de este tramo

- Suite Flutter completa: **54 pruebas aprobadas**, incluidas 12 nuevas para paginación, cambios de cuenta y rutas de dispositivos.
- Análisis estático Flutter sin incidencias tras corregir cinco observaciones de estilo; `git diff --check` aprobado en ambos repositorios.
- PHP: selftest (29), security_regression, walk_notification_features, walk_image_metadata_test, input_privacy_limits_test y firestore_pagination_atomic_test aprobados. Transporte simulado; sin envíos reales.
- Panel: sus 3 pruebas de selección y reintentos siguen aprobadas; no se modificó su interfaz.
- Astra revisó permisos, cursores, migración, cuota y revocación. Detectó que `FAILED_PRECONDITION` también llega como 400 en REST; se corrigió sin convertir errores de consultas en conflictos. No quedaron hallazgos concretos pendientes en el diff revisado. No es certificación de IAM/índices desplegados ni del borrado durable.

T05 continúa abierto: imágenes, recuperación y trabajos largos no están completados. Tampoco se declara la etapa 2 terminada. API y móvil nuevos todavía no están publicados.

## Tercer tramo de T05 — Fotos, 20/10/2026

- `PhotoInput` controla nuevas fotos en CRUD y perfil: formato real del encabezado, base64 canónico, tamaño, píxeles, estructura JPEG y metadatos. Rechaza nuevas URLs y SVG. Mantiene valores históricos idénticos sin descargarlos.
- Si el runtime tiene GD, decodifica y recodifica JPEG; sin GD no se afirma decodificación completa. Probado en ambos modos locales. No se impuso GD a Vercel sin verificar su disponibilidad.
- Flutter convierte las selecciones JPEG/PNG a JPEG real, normaliza orientación y elimina metadatos copiando solo píxeles. Dependencia `image` ya existente, sin rediseño.
- `photo_input_test.php` incluye controles de parser/metadata y escritura CRUD, aprobado con GD y sin extensiones. `photo_service_test.dart` agrega 5 pruebas aprobadas de conversión, reducción, EXIF/orientación y dimensiones excesivas. Las suites PHP anteriores siguen aprobadas. Suite Flutter completa aprobada con 57 pruebas antes de agregar las dos últimas; las 5 pruebas de foto se ejecutaron después. Análisis estático sin incidencias; tres pruebas JS del panel aprobadas.
- Revisión independiente Astra solicitada para este tramo, pero no pudo ejecutarse por límite de uso del modelo. Queda pendiente, sin transferir a otro modelo la revisión reservada por el usuario. La revisión aprobada del tramo anterior no cubre estos nuevos cambios de fotos.
- Recuperación permanece pendiente de implementación/ensayo: se dejó alcance técnico y criterios de aceptación sin afirmar una copia existente. Detalle: [T05_PHOTOS_RECOVERY.md](T05_PHOTOS_RECOVERY.md).

## Cuarto tramo de T05 — Decodificación y recuperación implementadas, 20/10/2026

- El caso sin GD ya no acepta fotos basándose solo en estructura: helper Node con `jpeg-js@0.4.4` decodifica y recodifica sin EXIF. Límites de memoria/píxeles, tiempo máximo de 10 s y respuesta 503 si no hay procesador disponible. Helper y dependencia añadidos a `includeFiles` de Vercel. npm local informó cero vulnerabilidades; no prueba ejecución del bundle desplegado.
- Reserva versionada de 30 intentos de foto/hora/UID antes del trabajo de procesamiento. Fallos consumen intento para limitar CPU; conservar/quitar una foto no consume. Registro de dispositivos conserva su cuota propia.
- `BackupExport` y `exportPage` implementan enumeración paginada de colecciones/documentos y descendientes de padres ausentes, preservando tipos REST. Nunca usan modelos móviles para reconstruir datos.
- `tool/recovery.py`: copia autenticada Fernet, clave y archivo fuera de los repositorios y en carpetas separadas, sin temporales planos; límite 64 MiB/10 min. Publicación del archivo cifrado sin sobrescribir destinos. Manifiesto/conteos/tipos validados antes de restaurar.
- Restauración restringida a loopback y proyecto `demo-*` distinto del origen, sin proxy/redirecciones ni sobrescrituras. Permite reintento comprobando igualdad de campos existentes. No se habilita restauración de producción, jobs ni envíos push.
- Evidencia final local: **59 pruebas Flutter aprobadas**, análisis sin incidencias, suites PHP previas aprobadas; pruebas nuevas de exportación, decodificación con GD/sin GD, falta de procesador (503) y cuota (429) aprobadas. **4 pruebas Python aprobadas** con archivos temporales sintéticos y servidor HTTP local; panel mantiene sus 3 pruebas aprobadas. No se invocó exportación de producción.
- Guía operativa y límites actualizados en [T05_PHOTOS_RECOVERY.md](T05_PHOTOS_RECOVERY.md). Pendiente prueba del bundle en Vercel, IAM/índices reales, ensayo en emulador oficial Firestore y revisión Astra (límite de uso). No se declara cumplida la aceptación completa de etapa 2 solo por disponer del código local.

## Etapa 2 — Verificaciones aplazadas por el usuario

El usuario indicó avanzar a etapa 3 dejando pendientes Vercel/bundle, IAM/índices reales, emulador oficial y revisión del último bloque de etapa 2. No se eliminan esos pendientes ni se convierte el estado local en aprobación de producción. La revisión Astra de T06 que sigue no sustituye esa revisión.

## Etapa 3 — Primer tramo de T06, 20/10/2026

- Centro «Privacidad y mis datos» en Perfil de Flutter, manteniendo componentes y paleta. Preferencias funcionales en servidor y avisos locales; retirar cuidados cancela pendientes locales sin otra consulta de red.
- Exportación propia autenticada con reauth, sesiones de 15 min, cursores aleatorios, cancelación, replay protegido por versiones y datos actuales/legacy. Proyecciones excluyen FCM, secretos y administradores ajenos. Flutter genera un JSON completo en memoria, descarta ante error/cambio de cuenta y requiere acción explícita para guardar/compartir.
- Solicitudes idempotentes y seguimiento propio implementados y probados con fixtures. El usuario indicó `pawlife.support@gmail.com`, ahora contacto visible de Soporte PawLife. La atención actual es por correo; formulario/POST de recepción en app deshabilitados hasta asignar acceso específico y procedimiento. No se envió correo ni se dieron permisos nuevos a administradores de avisos.
- Cuotas por operación, eventos de preferencias y limpieza de las nuevas colecciones en el borrado existente. No se afirma cumplimiento jurídico ni borrado durable.
- Verificación final: **73 pruebas Flutter aprobadas**, con 14 nuevas de privacidad (incluido contacto visible sin cola/permisos), y análisis sin incidencias. Suites PHP previas y `privacy_stage3_test.php` aprobadas, panel conserva sus 3 pruebas. Renders de privacidad a 320/390/800 px y texto 100%/200%; captura fixture inspeccionada.
- Astra revisó y confirmó correcciones de dos P2 (replay/cancelación concurrente y limpieza local sin segundo GET); no dejó hallazgos abiertos del alcance T06 inspeccionado. Sin producción ni prueba física de compartir.
- Contrato, límites, evidencia y pendientes: [T06_PRIVACY.md](T06_PRIVACY.md). T06 sigue parcial y T07 todavía no implementado.

## Retoma

Continuar etapa 3 desde pendientes explícitos de T06 y luego T07, con revisión Astra para privacidad/permisos. Verificaciones de etapa 2 aplazadas por instrucción del usuario. No afirmar seguridad integral, cumplimiento jurídico ni recuperación/borrado de producción. Mantener este registro actualizado. Los mensajes y alertas actuales del panel seleccionado siguen fuera del alcance de modificación. Sin publicidad US20, smartwatch ni funciones sociales. Los cambios requieren publicación coordinada API/móvil cuando el usuario la autorice; todavía no están en producción.
