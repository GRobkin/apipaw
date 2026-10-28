# PawLife — Plan de implementación

Fecha: 19/10/2026. Estado: especificación para implementación posterior, no funcionalidad entregada.

Seguimiento de la implementación iniciada después de esta auditoría: [IMPLEMENTATION_PROGRESS.md](IMPLEMENTATION_PROGRESS.md). El inventario de abajo describe la base auditada; consultar ese seguimiento para los cambios y pruebas posteriores.

## 1. Alcance, evidencia y reglas de ejecución

Se ejecutó `git pull --ff-only` en ambos repositorios, inicialmente sin cambios locales:

| Repositorio | Base actual auditada | Resultado |
|---|---|---|
| PawLife | `d9d1b08058ac87f05a22df633de3539ecbfdb46e` | Fast-forward; incorporados cambios de Guillermo |
| apipaw | `e47589566dd575c98e7a3e1b6dc25127b984073c` | Ya actualizado |

No aparecieron commits posteriores a las referencias de la consigna. Las rutas `PawLife/...` y `apipaw/...` son relativas a la carpeta que contiene ambos repositorios. Todo archivo, método, endpoint o colección marcado **NUEVO** es una propuesta; no existe por el solo hecho de aparecer aquí.

Esta entrega modifica únicamente documentación. No autoriza publicar, desplegar, cobrar, borrar cuentas reales, enviar avisos reales ni alterar interfaces. Antes de implementar, comprobar nuevamente HEAD, instrucciones y estado local. Preservar cambios del equipo. Trabajar por tareas pequeñas, con compatibilidad entre versiones móvil/API y autorización explícita para acciones externas.

Se reutilizaron `apipaw/docs/AUDITORIA_2026-10-17.md` y `PawLife/docs/PLAN_US15_US29.md`, contrastándolos con código. Método de revisión: skills `flutter-dart-code-review` de ECC, `api-and-interface-design` y `frontend-ui-engineering`; revisión focalizada de dependencias. Las observaciones visuales son de código, no una evaluación de pantallas renderizadas en esta sesión.

Arquitectura conservada: Flutter → Firebase Authentication → API PHP en Vercel → Firestore REST con cuenta de servicio; panel HTML/CSS/JS → misma API; FCM para push; Cloudflare para el programador. Fotografías pequeñas como data URI. No añadir Storage, Functions, bases paralelas ni un framework de administración por rutina.

### Verificaciones locales de esta revisión

| Verificación | Resultado y límite |
|---|---|
| `php tests/selftest.php` | 29 pruebas correctas con `OPENSSL_CONF=C:\php\extras\ssl\openssl.cnf`; primer intento falló en generación/firma de claves por configuración local |
| `php tests/security_regression.php` | Correcto |
| `php tests/walk_notification_features.php` | Correcto |
| `node --test tests/notification_batch_test.mjs` | 3 pruebas correctas |
| Flutter: `gps_point_filter_test`, `map_capture_test`, `walk_features_test`, `api_client_test` | Intento sin salida; interrumpido. No se contabiliza como aprobado ni como fallo funcional |
| Producción, OAuth real, compras, envío a celular, GPS físico, recuperación real | No probados en esta revisión |

Los tests locales no certifican seguridad integral, configuración desplegada ni recepción de notificaciones. No hay un teléfono de prueba disponible según la decisión previa del usuario.

## 2. Inventario definitivo y hallazgos

| Requisito | Estado comprobado | Trabajo pendiente |
|---|---|---|
| Mascotas, vacunas, medicamentos, alimentación, notas | Modelos, formularios/repositorios y CRUD API existentes | Regresión y controles transversales; no reconstruir |
| US24 peso | `fetchPesos`, `createPeso`, `deletePeso`; detalle muestra último peso y alta | Exponer historial fechado; verificar persistencia. Gráfico es opcional, no requisito adicional automático |
| US10–14 paseos | GPS, filtro, historial paginado, detalle y resumen existentes | Prueba física, límites de ruta, persistencia ante errores y regresión de navegación |
| US16 velocidad máxima | Métrica existente en paseo y filtro de saltos | Verificación con trazas y dispositivo; no añadir otra fórmula |
| US17 compartir PNG | `WalkShareScreen`, `WalkShareCard`, `WalkRouteTrace` existentes | Corregir mezcla de captura de mapa/foto y verificar archivo/compartir nativo |
| US15 inactividad | No se identificó generador de avisos de falta de paseos | T04 |
| US18 contraseña | Recuperación por correo existente; no cambio guiado en Perfil | T03 |
| US19 Google web | `AuthService.signInWithGoogle` ya usa `signInWithPopup` en web | Verificación OAuth real, dominio, cancelación y sesión API; no reimplementarlo |
| US21 Premium | Campos `premium`, `premiumHasta`; sin sistema de compra completo | T10–T11; beneficios, productos y cuentas pendientes |
| US22–23 tareas | Listado de recordatorios y marcado existente | Regresión; no crear otro sistema de tareas |
| US25 notas / US26 anticipación | Notas y `anticipacionDias` de vacunas existentes | Verificar alta/edición y reprogramación, no duplicar |
| US27 calorías | No estimación validada | T14 condicionado a validación veterinaria |
| US28 idiomas | `main.dart` fija `Locale('es')`, solo español | T12; Flutter solamente |
| US29 apariencia | Tema único; colores fijos en varias pantallas | T13, sin rediseño general |
| Panel | Selección de hasta 20 personas; cliente envía una petición por UID | Mantener selección, agregar global T09; no es solo una persona |
| Push | Registro tras sesión, renovación de token y manejadores existentes | Falta manejo explícito de `getInitialMessage`; entrega real pendiente |
| Privacidad | Borrado básico con autenticación reciente | Exportación, derechos, preferencias y borrado reanudable T06–T07 |
| Moderación | Validación de formato; no filtro contextual completo | T08 |
| Distribución | Android release utiliza firma debug | T15; no distribuir así |

### Cambios de Guillermo que se deben conservar

- `services/gps_point_filter.dart` filtra precisión, lecturas anteriores al inicio, orden temporal y saltos; `route_view_model.dart` calcula con puntos aceptados. `walk_task_handler.dart` transmite precisión y el timestamp de la posición. Mejoran la entrada GPS, pero no demuestran exactitud en exteriores. Probar quietud, pérdida de señal, reanudación y desplazamiento real; no ajustar umbrales por intuición.
- `services/map_capture_service.dart` captura el mapa renderizado, reduce hasta 720 px, comprime JPEG y limita el data URI. `route_screen.dart` utiliza `RepaintBoundary`. Es una captura del encuadre actual al pulsar, no necesariamente de todo el paseo terminado.
- `_CuerpoPestana` en `pet_detail_screen.dart` utiliza `CustomScrollView`/`SliverFillRemaining` para estados vacíos/carga. Mantener y probar con teclado, listas largas y texto ampliado.
- La cámara convencional del paseo fue reemplazada por captura del mapa; `PhotoService` sigue disponible para mascotas. Recomendación: conservar captura de mapa y ofrecer foto convencional como opción separada únicamente si el equipo confirma que la quiere en paseos. No revertir el commit.

### Riesgos concretos prioritarios

1. **PNG y ubicación:** `WalkShareCard` usa `paseo.fotoUrl ?? mascota.fotoUrl`; ahora `paseo.fotoUrl` puede contener calles capturadas. El texto «No incluye calles ni coordenadas» deja de ser cierto. La traza sin mapa también puede revelar patrones; no prometer anonimato.
2. **Borrado parcial:** `DELETE /api/me` borra secuencialmente y elimina Auth al final, sin checkpoint ni bloqueo de nuevas escrituras. Un corte o actividad concurrente puede dejar datos huérfanos. La configuración administrativa y referencias como `administradorId` requieren tratamiento explícito.
3. **Entradas voluminosas:** `Schema::toRoute` valida coordenadas pero no limita cantidad de puntos. No hay presupuesto agregado de documento/ruta/foto. Algunas lecturas filtradas no están paginadas; borrar/exportar no puede depender de una lectura completa.
4. **Imagen:** validar prefijo/base64 no demuestra que sea JPEG válido, ni elimina metadatos. Mascota/perfil no tienen el mismo control específico que paseo. No descargar URLs arbitrarias desde PHP para moderarlas.
5. **Push:** el envío manual puede quedar en estado procesando después de una interrupción. El programador puede repetir una notificación si FCM la aceptó y falla el guardado posterior. No hay garantía general de exactamente una entrega en FCM.
6. **Privacidad operativa:** el foreground de paseo muestra coordenadas; logs PHP incluyen mensaje/trace de excepciones. Revisar exposición en pantalla bloqueada y redacción de logs. Tokens FCM se usan como ID del documento/dispositivo y como segmento de URL; migrar identificadores sin romper móviles anteriores.
7. **Límites y permisos:** existe aislamiento por UID y comprobación de cuenta activa/revocada. Mantenerlos. `firestore.rules` niega acceso directo, pero la cuenta de servicio los omite: PHP debe autorizar cada operación nueva. No se encontró una vulnerabilidad de lectura cruzada demostrada en esta revisión.

## 3. Contratos y decisiones comunes

- Mantener rutas actuales y formato `{error:{status,message,details?}}`; añadir `code` estable de forma compatible para traducción/reautenticación. Nunca exponer stack, payload privado o respuesta cruda del proveedor al cliente.
- UID siempre del token verificado; `userId` del cuerpo solo en operaciones administrativas autorizadas. Recurso ajeno: 404. Sesión inválida: 401; permiso insuficiente: 403; conflicto de intención: 409; validación/moderación: 422; cuota: 429; dependencia indisponible: 503.
- Operaciones sensibles: reutilizar `AuthenticatedUser::requireRecentAuthentication` (ventana actual 300 s), verificar cuenta activa y alcance del rol. No confiar en correo, flags del cliente, CORS o controles visuales.
- Nuevas mutaciones reintentables usan `solicitudId` estable por intención, persistido antes de enviar desde cliente. Registro backend por actor+operación+ID y hash de contenido normalizado: mismo ID/cuerpo devuelve resultado; distinto cuerpo da 409. Reserva atómica con create/precondición, nunca read-then-write desprotegido.
- Trabajos largos: Firestore como cola duradera mínima, cursor, estado, lease, intentos y errores redactados; Cloudflare invoca una ruta interna firmada. Propuesta de ejecución acotada a <45 s por request frente a los 60 s configurados, con lotes pequeños medidos. No continuar trabajo en segundo plano después de responder desde Vercel.
- `FirestoreClient` ya tiene `createDocument`, `getDocumentVersioned`, `patchDocument`, `walkHistoryPage` y `queryDateWindowPage`. **NUEVOS:** `queryPage` con orden estable/cursor ligado al filtro/UID y operación de commit con precondiciones para invariantes de varios documentos. No fingir que ya hay transacciones genéricas.
- Cursores validados, límites acotados y allowlist de ordenamiento. Índices en `PawLife/firestore.indexes.json`; documentar también los nuevos accesos para el backend. Excluir blobs de foto/ruta de índices cuando corresponda y verificar compatibilidad, sin borrar datos.
- Todo trabajo usa fixtures/emulador o proyecto sandbox separado. No reutilizar usuarios reales para pruebas destructivas ni tokens FCM reales en tests.

## 4. Etapa 1 — Cerrar lo existente

### T01. Paseos, captura y PNG seguro

1. **Origen:** US10–17, cambios de Guillermo y requisito PNG.
2. **Actual:** servicios GPS/captura, historial y exportación existen; riesgo de ubicación descrito arriba.
3. **Objetivo:** conservar la ruta completa y ofrecer un PNG verificable del paseo finalizado con duración, distancia y traza, sin incluir mapa callejero por defecto.
4. **Archivos:** `PawLife/lib/services/{gps_point_filter,map_capture_service,walk_repository,walk_task_handler}.dart`, `lib/viewmodels/route_view_model.dart`, `lib/models/pawlife_models.dart`, `lib/views/{route_screen,walk_share_screen,walk_detail_screen,walk_summary_screen}.dart`, `lib/views/widgets/walk_route_trace.dart`; `apipaw/src/Resources/{Catalog,CareValidator,Schema}.php`.
5. **Cambios:** separar semántica de imagen con **NUEVO** `imagenTipo: foto|mapa|desconocida`; no inferir origen de registros antiguos. En compartir usar foto de mascota o foto expresamente identificada, nunca `fotoUrl` desconocida/mapa automáticamente. La captura original queda en detalle privado. Esperar render/carga y manejar tile fallido; si se ofrece captura de recorrido completo, ajustar bounds a todos los puntos y distinguirlo del encuadre actual. Verificar atribución visible del proveedor cartográfico al mostrar/capturar tiles. No quitar fotografía sin decisión del equipo.
6. **API:** conservar CRUD `/api/mascotas/{id}/paseos`; campo opcional nuevo, desconocido por defecto. No hay endpoint nuevo para generar PNG: se genera localmente.
7. **Datos:** ruta original privada intacta; metadato imagenTipo compatible. PNG temporal sin coordenadas EXIF, con traza normalizada; limpiar archivo temporal después del uso según ciclo seguro del share sheet.
8. **Autorización/validación:** propietario del paseo y mascota; imagen validada T05; no considerar `imagenTipo` una excepción a moderación ni prueba de confianza. Notificación de foreground sin lat/lng explícitos.
9. **Dependencias:** T05 límites; decisión opcional foto convencional. No requiere proveedor nuevo.
10. **Pruebas:** filtros con NaN/infinito/rangos, jitter, silencio, timestamps desordenados; PNG decodificable, incluye métricas correctas, no mapa antiguo; ruta vacía/un punto/larga; capture tras resize/tiles pendientes; share/cancel en Android/iOS; paseo con pantalla bloqueada y vuelta a app.
11. **Aceptación:** imagen final exportada revisada visualmente, trazado y métricas corresponden al paseo persistido; historial abre el mismo registro; errores conservan trabajo; sin promesa falsa de privacidad.
12. **Riesgos/decisiones:** imagen ya compartida no puede retirarse; traza puede ser identificable. Foto convencional opcional pendiente; cobertura GPS requiere teléfono, no se sustituye por unit tests.

### T02. Historial de peso y regresión de cuidados

1. **Origen:** US24 y terminar funciones existentes.
2. **Actual:** `PawLifeRepository.fetchPesos/createPeso/deletePeso` y `_registrarPeso` existen; detalle consume `_ultimoPeso`, sin historial visible completo.
3. **Objetivo:** consultar registros con fecha y kg, agregar y eliminar con confirmación y refresco coherente.
4. **Archivos:** `PawLife/lib/views/pet_detail_screen.dart`, `lib/services/pawlife_repository.dart`, `lib/models/pawlife_models.dart`; **NUEVO** `lib/views/weight_history_screen.dart`; API `Catalog::pesos`, `CareValidator`, `ResourceController`.
5. **Cambios:** lista ordenada por fecha/ID; estados vacío/carga/error y acceso desde peso actual. Conservar formulario existente y precisión decimal. Probar CRUD de vacunas/medicamentos/alimentación/notas y marcado de tareas sin reconstruirlos.
6. **API:** reutilizar `/api/mascotas/{id}/pesos`; paginación compatible T05 si el volumen lo exige. No introducir un endpoint de estadísticas solo para una lista.
7. **Datos:** mismos `fecha`, `valorKg`, `userId`, `mascotaId`; no recalcular ni reemplazar históricos.
8. **Autorización/validación:** peso positivo finito; alinear API y UI (UI hoy tope 200 kg). El tope definitivo depende de especies admitidas; no imponer un límite veterinario inventado. Propiedad de mascota obligatoria.
9. **Dependencias:** T05 para paginación. Gráfico no bloquea cierre.
10. **Pruebas:** fecha igual con desempate, coma decimal, cero/negativo/NaN, dueño ajeno, borrar último, offline/error sin duplicación por reintento; cuidados y recordatorios asociados.
11. **Aceptación:** historial persiste tras reiniciar y coincide con API; último peso siempre es el más reciente por fecha.
12. **Riesgos/decisiones:** no mostrar diagnóstico de sobrepeso ni peso ideal sin modelo validado.

### T03. Contraseña, Google y apertura desde push

1. **Origen:** US18, US19 y cierre de notificaciones.
2. **Actual:** reset por correo y Google web/nativo existen; `SessionRegistration` enlaza perfil, recordatorios y FCM después de login; falta cambio de contraseña guiado y `getInitialMessage` explícito.
3. **Objetivo:** completar seguridad de sesión y pruebas reales sin rehacer autenticación.
4. **Archivos:** `PawLife/lib/services/{auth_service,session_registration,push_service,api_client}.dart`, `lib/views/profile_screen.dart`, `lib/main.dart`; `apipaw/src/Auth/{FirebaseTokenVerifier,FirebaseAuthAdmin,AuthenticatedUser}.php`.
5. **Cambios:** **NUEVOS** métodos `reauthenticateForSensitiveAction` y `changePassword`; reauth según proveedor vinculado. Cuenta solo Google no pide una contraseña inexistente. Refresh del ID token tras reauth. Procesar mensaje inicial una sola vez tras sesión lista, validar recurso/propietario y tolerar recurso eliminado. Preservar cancelación de generación en SessionRegistration.
6. **API:** contraseña permanece en Firebase Auth SDK, nunca pasa por PHP. API existente conserva verificación `assertActive`; códigos estables de reauth en errores sensibles.
7. **Datos:** no guardar contraseña, credenciales OAuth ni ID tokens nuevos en Firestore. Preferencia de proveedor no sustituye datos reales de Firebase Auth.
8. **Autorización/validación:** política real de Firebase; evitar mensajes que permitan enumerar cuentas; cuenta eliminada/deshabilitada/revocada rechazada.
9. **Dependencias:** OAuth/domains/SHA configurados por responsable; cuenta sandbox y dispositivo para prueba FCM. Sin pedir secretos por chat.
10. **Pruebas:** password incorrecta, cancelación, sesión antigua, Google sin password, logout/login otro UID, renovación token, popup bloqueado/cancelado, dominio no autorizado; foreground/background/cold start y permisos denegados.
11. **Aceptación:** cambio real sandbox y acceso posterior; web Google obtiene sesión válida ante PHP; abrir push no muestra datos del UID anterior.
12. **Riesgos/decisiones:** recepción real sigue pendiente sin dispositivo; no confundir FCM aceptado con notificación mostrada.

### T04. US15 — Inactividad sin paseos

1. **Origen:** US15.
2. **Actual:** `PushDispatcher::dispatch` consume recordatorios fechados; no genera inactividad.
3. **Objetivo:** un aviso por episodio de inactividad, configurable y desactivable; sin repetir cada ejecución de cron.
4. **Archivos:** `apipaw/api/index.php`, `src/Notifications/PushDispatcher.php`, `src/Resources/Catalog.php`, `cloudflare/src/index.js`; **NUEVO** `src/Notifications/InactivityReminderService.php`; Flutter Perfil/repositorio/modelos.
5. **Cambios:** preferencia opt-in con intervalo elegido (sin valor clínico supuesto); actualizar último paseo completado en servidor. Job paginado calcula vencimiento; crear recordatorio con ID determinista mascota+último-paseo/episodio. Revalidar al enviar; completar nuevo paseo invalida aviso previo. En mascotas sin paseos usar alta como origen solo si el usuario habilitó la opción.
6. **API:** **NUEVO** PATCH `/api/me/preferences` compartido con T06/T12/T13, `{inactividad:{habilitada,intervaloHoras}}`; no endpoint público para disparar el cron.
7. **Datos:** preferencias de usuario y estado por mascota, recordatorio `tipo=inactividad` generado por servidor. Reconciliar existentes por lotes; UTC y zona IANA para presentación/horario elegido.
8. **Autorización/validación:** solo propio usuario; intervalo dentro de límites de producto confirmados. Índice de elegibilidad; no escanear todo cada cinco minutos. Guardar versión del episodio para evitar carrera con paseo nuevo.
9. **Dependencias:** T05 job/claim y T06 preferencias; realizar después de esa base, aunque pertenezca al cierre funcional.
10. **Pruebas:** antes/en/después del umbral, cron repetido/concurrente, pasear mientras se prepara aviso, borrar mascota/cuenta, preferencia apagada, retraso del cron y zona horaria.
11. **Aceptación:** un episodio genera como máximo un recordatorio lógico; desactivarlo impide nuevos envíos; no requiere abrir app.
12. **Riesgos/decisiones:** intervalo y horas de descanso por confirmar; pérdida/duplicación física FCM tratada como T09, no prometer entrega exacta.

## 5. Etapa 2 — Seguridad y recuperación

### T05. Endurecimiento transversal proporcional

1. **Origen:** RNF01, auditoría de seguridad, privacidad y fiabilidad.
2. **Actual:** token RS256/aud/iss/exp y cuenta activa, propiedad de recursos, allowlist admin y reglas deny-all existentes. Falta cobertura de límites agregados, decodificación de imágenes, paginación general y recuperación robusta.
3. **Objetivo:** cerrar los riesgos de §2 y proporcionar primitivas seguras a las tareas nuevas.
4. **Archivos:** `apipaw/api/index.php`, `src/Resources/{ResourceController,Schema,CareValidator,Catalog}.php`, `src/Firestore/FirestoreClient.php`, `src/Http/{Request,Response}.php`, `src/Notifications/{ManualNotification,FcmSender,PushDispatcher,ReminderRateLimiter}.php`; `PawLife/firestore.rules`, `firestore.indexes.json`, `lib/services/{pawlife_repository,photo_service}.dart`.
5. **Cambios:** limitar body antes de parsear; presupuestar tamaño serializado Firestore con margen bajo 1 MiB, longitud de ruta y campos. Simplificación de ruta solo con tolerancia y pruebas, nunca truncar silenciosamente. Allowlist de orderBy/paginación. Validar decode real, píxeles, formato, tamaño y reencode de fotos sin EXIF en backend; confirmar extensión disponible en runtime PHP, no asumir GD. Rechazar SVG/URLs arbitrarias nuevas. Rate limits por UID/operación y presupuesto especial para moderación/búsqueda/export. Redactar logs por código/requestId. IDs de dispositivos opacos/hash estable en servidor; migración compatible y dedup para instalaciones anteriores, sin tokens en URLs nuevas.
6. **API:** preservar CRUD; **NUEVO** registro de dispositivo con `installationId` y `token` en body (ruta existente), respuesta ID opaco. Migración transitoria de endpoints antiguos sin registrar segmentos sensibles. Lecturas paginadas retornan `{items,nextCursor}` adicional y no cambian a espaldas del cliente listas completas existentes; actualizar consumidores en el mismo tramo de entrega.
7. **Datos:** claims/jobs/limits internos no editables por clientes; device owner y token único actual; estructuras mínimas y retención aprobada. Nuevas queries con cursor atado a UID/filtro; no usar `total=count(items)` como total global.
8. **Autorización/validación:** pruebas de IDOR en toda ruta y padres; no modificar `premium`, roles ni `userId` desde CRUD; proteger rutas internas con firma, fecha, método y path; si tienen cuerpo, firmar también digest. Revisar IAM mínimo de cuenta de servicio, secretos fuera de Git, allowlist CORS real y CSP sin romper Firebase/panel. No declarar CORS como autorización.
9. **Dependencias:** validar extensiones PHP, cuotas actuales, variables y acceso IAM con autorización. Nada de cifrado casero ni abrir Firestore al cliente.
10. **Pruebas:** payload grande antes de decode, JPEG falso/bomba de píxeles/EXIF, ruta extensa y corrupta, cursor manipulado, UID ajeno, body privilegiado, rol retirado, token revocado, concurrencia/idempotencia, 429 y reintento; análisis de secretos sin imprimirlos.
11. **Aceptación:** límites documentados y coherentes móvil/API; no exposición de credenciales/GPS en logs; aislamiento demostrado en tests; cualquier error conserva borrador y no genera escrituras parciales silenciosas.
12. **Riesgos/decisiones:** no hay presupuesto/retención aprobados. Copia de seguridad: preparar exportación administrativa paginada, cifrada con herramienta estándar y clave separada, fuera de Git; restaurar primero en sandbox y registrar tiempos. Elegir responsable, frecuencia, RPO/RTO y retención antes de automatizar. No activar backups pagos ni afirmar que una copia manual garantiza disponibilidad.

## 6. Etapa 3 — Privacidad, LGPD y derechos

### Matriz de datos para T06/T07

Convenciones: **B** = base jurídica por finalidad pendiente de validación del responsable, no consentimiento universal; **R** = retención justificada pendiente, incluida eliminación de copias y terceros. Acceso común: titular mediante API; procesos PHP autorizados; operadores con IAM, que deben limitarse. El panel no debe dar lectura general de datos privados.

| Categoría y existencia | Finalidad/base | Almacenamiento/acceso específico | Retención | Exportación | Corrección | Eliminación/anonimización |
|---|---|---|---|---|---|---|
| Perfil/correo: real | Identidad/cuenta; B | Firebase Auth + `users`; admin actual solo búsqueda exacta mínima | R | JSON propio, ambos orígenes | Nombre API; correo Auth con verificación/reauth | Auth+perfil; conservar solo lo legalmente justificado |
| Mascotas/notas: real | Cuidados; B | `mascotas` por userId | R | JSON | CRUD propio | Cascada verificada |
| Fotos: real | Identificación/recuerdo; B | data URI en perfil/mascota/paseo | R | Archivo/valor propio, control de tamaño | Sustituir/eliminar | Documento, caché y temporales; limpiar metadatos |
| Vacunas/medicamentos/alimentaciones/pesos: reales | Historial/rutina; B | Colecciones raíz respectivas, userId+mascotaId | R | JSON con fechas/unidades | CRUD permitido; no inventar edición UI existente | Cascada y comprobación |
| Paseos/GPS: real | Recorrido/métricas; B + permiso OS distinto de base jurídica | `paseos.ruta`, captura en fotoUrl; tiles consultados a tercero | R | JSON explícito con coordenadas; advertir sensibilidad | Corregir registro permitido, no falsear ruta silenciosamente | Ruta/foto/caché/temporales |
| Recordatorios/notificaciones: reales | Cuidados y comunicación; B separada por finalidad | `recordatorios`, `notificaciones`, FCM, notificaciones locales OS | R | Propios, sin datos de otros ni tokens | Preferencias/recordatorios; logs no alterables libremente | Cancelar futuros; retirar locales; TTL/limpieza aprobada |
| Tokens FCM: reales | Entrega; B | `dispositivos`; proveedor FCM; backend | R hasta rotación/revocación y política aprobada | Metadata de dispositivos; excluir token secreto del archivo común | Registro/rotación automática | Desregistrar y borrar, no solo ocultar |
| Preferencias: parciales/nuevas | Idioma/tema/avisos; B | zonaHoraria en users; nuevas preferencias y storage local | R | JSON | Titular, enums válidos | Cuenta + dispositivo |
| Premium: campos reales, compras futuras | Acceso contratado/concesión; B | users proyección; nuevas suscripciones/grants restringidos | R | Estado y origen propio | Servidor/proveedor/admin autorizado | Retención legal separada, no borrar comprobante obligado |
| Compras: nuevas | Pago/conciliación; B contractual/legal a validar | Proveedor y referencias mínimas en backend | R legal por país | Historial pertinente sin secretos | Proveedor/soporte, sin modificar transacciones | Supresión/seudonimización legal; hash no equivale a anonimato |
| Seguridad/ratelimits/jobs: reales y ampliados | Seguridad/operación; B | logs Vercel/Cloudflare, `system`, límites; IAM restringido | R por clase | Acceso revisado para no exponer seguridad/terceros | Rectificación anotada cuando corresponda | Purga controlada y excepciones documentadas |
| Solicitudes/consentimientos/moderación: nuevos | Derechos/prueba de decisión; B | colecciones internas, sin fotos/notas originales por defecto | R | Estado/historial propios pertinente | Nueva versión/retiro, no reescritura audit trail | Minimización, revisión de obligaciones |

No se verificaron región de datos, subencargados, plazos de terceros ni contratos en las consolas. Documentar Firebase/Google, Vercel, Cloudflare, proveedor de tiles y futuros pagos/moderación con configuración real antes de publicar política. Permisos Android/iOS no sustituyen consentimiento jurídico; retirar un consentimiento no borra automáticamente tratamientos de otra base válida.

### T06. Centro de privacidad, corrección y exportación

1. **Origen:** LGPD explícita, Ley 18.331 cuando aplique, requisitos plataformas.
2. **Actual:** Perfil edita nombre; datos propios disponibles por CRUD. No centro, exportación completa ni solicitudes trazables.
3. **Objetivo:** acceso/corrección/exportación/portabilidad y solicitudes no automatizables con información clara, sin prometer cumplimiento jurídico integral.
4. **Archivos:** `PawLife/lib/views/profile_screen.dart`, repositorio/Auth/modelos; **NUEVOS** `lib/views/privacy_screen.dart`, `apipaw/src/Privacy/{PrivacyService,ExportService}.php`; router, FirestoreClient y Catalog existentes.
5. **Cambios:** una pantalla organizada: datos, preferencias, descargar, eliminar, contactar/solicitar derecho. Nombre por API; correo por flujo Auth verificado con reauth y actualización segura de perfil. Formularios para bloqueo/anonimización/oposición/acceso no automatizable con seguimiento propio; responsable y canal reales obligatorios, no correo inventado. Permitir retirar consentimiento opcional sin cerrar cuenta.
6. **API NUEVA:** GET `/api/me/privacy` → versiones/políticas/preferencias/canal; PATCH `/api/me/preferences` → allowlist; POST `/api/me/privacy-requests` `{tipo,detalle,solicitudId}` → 202 `{id,estado}`; GET propia solicitud. POST `/api/me/exports` con reauth → sesión de exportación, GET `/api/me/exports/{id}?cursor=...` → páginas autenticadas del manifiesto y datos. No URLs públicas ni datos en query strings.
7. **Datos:** **NUEVOS** `privacyRequests`, `consentEvents`, `exportSessions` con owner, versión, estado, timestamps; sesiones contienen cursores, no duplicados completos de GPS/fotos. Export por páginas con versión de esquema y manifiesto; Flutter ensambla archivo local mediante mecanismo seguro y avisa datos sensibles. Cambios durante exportación: marcar rango temporal/consistencia y permitir regenerar; no declarar snapshot transaccional si no lo es.
8. **Autorización/validación:** solo titular, reauth para export; caducidad corta de sesión y cancelación al borrar/revocar; rate limit y tamaño de detalle. Excluir credenciales/token FCM/purchase tokens/datos de terceros; no excluir categorías personales sin explicar vía de acceso complementaria. Solicitudes manuales accesibles solo a rol específico, no a todos los administradores de avisos.
9. **Dependencias:** T05 paginación y límites; responsable legal/contacto/bases/retención reales; política multilingüe T12. Puede implementarse con fixtures sin publicar política ficticia.
10. **Pruebas:** usuario A no exporta B; categorías completas y >una página; fotos grandes, cursor vencido, cambio de sesión, cancelación, correo no verificado, retiro preferencias, solicitudes duplicadas y seguimiento ajeno rechazado.
11. **Aceptación:** descarga legible, sin secretos, con todas las categorías y excepciones explicadas; solicitud tiene responsable, estado y procedimiento de respuesta, no un formulario sin atención.
12. **Riesgos/decisiones:** validar aplicabilidad LGPD/Uruguay, plazos legales y portabilidad; soporte manual requiere dueño operativo. Cifrar archivos de backup/export retenidos por servidor con herramienta estándar; no persistir exportaciones completas allí si no es necesario.

### T07. Completar eliminación existente y vía web

1. **Origen:** derecho de supresión y cuenta; extender `DELETE /api/me`.
2. **Actual:** autenticación reciente comprobada; borrado síncrono de colecciones raíz y legacy y Auth al final, sin recuperación durable.
3. **Objetivo:** borrado reanudable, verificable y sin nuevas escrituras del usuario durante el proceso.
4. **Archivos:** `apipaw/api/index.php`, FirebaseAuthAdmin, FirestoreClient; **NUEVO** `src/Privacy/AccountDeletionService.php`; Flutter AuthService/Perfil/repositorio; **NUEVA** página web `panel/privacy.html` y ruta pública informativa `/privacidad`.
5. **Cambios:** reutilizar validación reciente y cascadas. Crear atómicamente tombstone/job antes del borrado; todos los escritores y workers, incluido `ensurePerfil`, consultan barrera y la verifican mediante precondición en commits para cerrar carreras. Reauth guiada Flutter. Bloquear nuevos dispositivos/avisos/pagos vinculados; resolver suscripción y retención sin confundir borrar cuenta con cancelar tienda. Borrar en páginas checkpoints; verificar cero documentos pendientes antes de Auth. Reiniciar tras cualquier corte. No borrar el checkpoint hasta reconciliación final.
6. **API:** `DELETE /api/me` pasa a 202 `{solicitudId,estado}` cuando es async, con compatibilidad del cliente anterior revisada; **NUEVO** GET `/api/me/deletion` mientras Auth existe. Tras eliminar Auth, no exigir token ya inválido para terminar el job; pantalla puede confirmar solicitud aceptada, y el proceso guarda comprobación interna. Vía web debe autenticar o iniciar solicitud manual con verificación segura, nunca borrar por correo escrito en formulario.
7. **Datos:** **NUEVOS** `accountDeletionJobs` y tombstone mínimo. Inventario raíz: users, mascotas, vacunas, medicamentos, alimentaciones, pesos, paseos, recordatorios, dispositivos, notificaciones; legacy bajo users; límites conocidos; config admin UID; nuevas privacy/export/grants/purchases/jobs. Para notificaciones creadas por admin a otros: minimizar referencia del actor según política, no borrar datos ajenos indiscriminadamente.
8. **Autorización/validación:** dueño y reauth; worker interno firmado y privilegio mínimo; usuarios ya en borrado no pueden recrear perfil. Retención legítima separada, acceso restringido y motivo/plazo aprobado, no excusa genérica para conservar todo.
9. **Dependencias:** T05 commits/jobs, T06 información; T10 aporta tratamiento suscripción y webhook posterior. Firebase Auth deletion 404 debe tratarse idempotentemente.
10. **Pruebas:** fallo inyectado en cada etapa, más de una página, doble solicitud, escrituras simultáneas, Auth borrado antes de retry, legado, admin UID, webhook tardío, mascota borrada concurrentemente, restauración de backup no resucita cuentas suprimidas.
11. **Aceptación:** cero datos residuales fuera de excepción documentada; job termina tras reintentos; usuario entiende diferencia entre solicitud y finalización. Registro de verificación sin copiar contenido borrado.
12. **Riesgos/decisiones:** retención tombstone y prueba de supresión requiere plazo aprobado; no revocar Auth antes de permitir confirmar acciones de suscripción que requieran sesión. Recuperación de datos no debe revertir solicitudes de eliminación.

## 7. Etapa 4 — Moderación privada

### T08. Texto e imágenes antes de guardar

1. **Origen:** filtro obligatorio incluso sobre contenido no compartido.
2. **Actual:** Schema/CareValidator validan formato, no contenido contextual; fotos data URI.
3. **Objetivo:** política consistente es/pt/en, no eludible por API directa, sin perder borrador ni mostrar fotos privadas a administradores rutinariamente.
4. **Archivos:** `apipaw/src/Resources/{ResourceController,CareValidator,Catalog}.php`, router para perfil/avisos; **NUEVOS** `src/Moderation/{ModerationService,TextModerator,ImageModerator}.php`; Flutter formularios/PhotoService/ApiClient.
5. **Cambios:** servicio central antes de create/patch final, solo campos modificados. Texto: nombre/perfil, mascota, notas, alimentación, medicamento/dosis, vacuna, recordatorio y mensajes admin; preservar nombres clínicos. Política propuesta: rechazar amenaza dirigida creíble, ataque discriminatorio, insulto grave dirigido y descripción sexual explícita sin contexto veterinario legítimo; aceptar citas descriptivas clínicas inocuas; dudoso no guardar y pedir reformular/reintentar, con vía de revisión voluntaria informada. No depender de regex/lista de palabras como decisión final. Imagen: decode/reencode T05 → clasificador → persistencia. Captura marcada mapa por cliente sigue siendo entrada no confiable.
6. **API:** misma ruta CRUD; 422 con `code=CONTENT_REJECTED|CONTENT_AMBIGUOUS`, campo y motivo seguro; 503 `MODERATION_UNAVAILABLE` reintentable. Aceptado sigue éxito habitual. Sin respuesta cruda del clasificador ni imagen/texto en logs.
7. **Datos:** auditoría mínima hash protegido/contexto de campo, versión política/modelo, estado y fecha; no copia del contenido rechazado. Cache por usuario+hash+versión, retención pendiente. Draft sensible solo en memoria por defecto; no persistencia local silenciosa.
8. **Autorización/validación:** moderación backend en todos los escritores, incluido admin y futuras funciones; no rutas alternativas bypass. Timeout/cuota; ante error conservar borrador cliente y no confirmar guardado. No mandar contenido privado al proveedor hasta aprobar condiciones y actualizar información al usuario.
9. **Dependencias:** T05 validación y T06 privacidad. Adaptador de texto contextual pendiente de proveedor/costo/retención/calidad; no afirmar que una lista local cumple. SafeSearch candidato de imagen: requiere habilitar facturación; incompatible con mantener cero facturación sin nueva decisión. No montar un modelo pesado en Vercel PHP para esquivar esa decisión.
10. **Pruebas:** corpus es/pt/en con amenazas/ofensas y controles veterinarios como «castración», «sangrado», «tumor», «mamas»; imágenes benignas de animales y casos ambiguos, sin recopilar material ilegal; API directa/PATCH/cambio de encoding, timeout, cuota, proveedor 5xx; no escrituras ante rechazo.
11. **Aceptación:** corpus etiquetado por humanos y umbrales de falsos positivos/negativos acordados; resultados distinguibles; ningún bypass conocido; privacidad y presupuesto aprobados antes de producción. Fixtures no equivalen a validación real del proveedor.
12. **Riesgos/decisiones:** no existe filtro perfecto. Aprobar política y proveedor; revisar falsos positivos de heridas clínicas. No exponer automáticamente contenido a un panel de moderadores ni construir red social.

## 8. Etapa 5 — Envío global simple

### T09. «Enviar a todos» conservando selección y avisos actuales

1. **Origen:** consigna §6–7.
2. **Actual:** `panel/app.js` mantiene Map de hasta 20 destinatarios; `notification-batch.mjs` genera IDs por persona. `ManualNotification::send` admite un UID y hasta 3 dispositivos por persona, no 3 personas. FCM recibe mensajes individuales.
3. **Objetivo:** alternativa Todos con revisión/confirmación y progreso real, sin gestor de campañas ni tocar avisos del envío seleccionado que el usuario quiere probar.
4. **Archivos:** `apipaw/panel/{index.html,app.js,notification-batch.mjs}`, `src/Notifications/{ManualNotification,FcmSender,PushDispatcher}.php`, `src/Auth/NotificationAdmin.php`, router, FirestoreClient, `cloudflare/src/index.js`; **NUEVO** `src/Notifications/GlobalNotification.php`.
5. **Cambios:** elegir **lotes servidor duraderos, no topic público**: necesitamos elegibilidad controlada, bajas, resultados y reanudación. Selector Personas/Todos; revisar mensaje y confirmar explícitamente alcance. Congelar cutoff de audiencia al iniciar, paginar usuarios/dispositivos existentes antes del cutoff; revalidar cuenta/preferencia/token justo antes de enviar. Nuevos usuarios no entran en un trabajo ya iniciado. Sin banners, fechas de campaña, borradores ni historial administrativo independiente.
6. **API NUEVA:** POST `/api/admin/notifications/global` `{mensaje,solicitudId}` → 202 `{id,estado}`; GET `/api/admin/notifications/global/{id}` → contadores/progreso, sin tokens. **NUEVO** POST interno `/api/internal/process-notification-jobs` firmado. Preservar POST `/api/admin/notifications` y GET `/api/admin/notification-users` y sus avisos actuales.
7. **Datos:** **NUEVOS** `notificationJobs/{id}` con actor/hash/cutoff/cursor/lease; items deterministas `jobId+tokenHash` con ownerId/estado/attempts. Dedup de tokens global; usuario elegible activo/no borrando/avisos habilitados/dispositivo válido. Registrar omitidos por motivo y evitar guardar copias de perfiles. El límite actual de 3 dispositivos no se reutiliza como límite de personas: global procesa dispositivos elegibles paginados; política final por cuenta explícita, sin recorte silencioso.
8. **Autorización/validación:** permiso admin validado al crear y al procesar; detener si se revoca el permiso. Mensaje 1..240 caracteres como actual, moderación T08, rate limit de trabajos y confirmación. FcmSender distingue errores permanentes/transitorios/resultado incierto; desactivar token solo por código verificable de token inválido, no por timeout o error de credenciales global.
9. **Dependencias:** T05 jobs/paginación, T06 preferencias, T08. Reutilizar cron existente con presupuesto separado para no bloquear recordatorios; medir lectura/escritura y cuotas antes de subir volumen.
10. **Pruebas:** 0/1/muchas páginas de usuarios, token duplicado entre registros, bajas entre páginas, cron concurrente, idempotency distinto body, permisos revocados, token inválido, 429/5xx, corte antes/después de FCM y antes de commit; seleccionado funciona igual.
11. **Aceptación:** cada item tiene una única decisión persistida; recuento distingue aceptado por FCM, fallo definitivo, omitido y **resultado incierto**. Nunca mostrar entregado sin evidencia. No reintentar ciegamente un envío incierto: requiere política explícita de recuperación; así se evita duplicación por retry automático a costa de un posible no envío. Tests con sender simulado verifican que items confirmados no se repiten.
12. **Riesgos/decisiones:** exactamente una visualización no es garantizable con FCM. Incluir ID estable en data y dedup en app donde controle presentación; no prometer que evita duplicados de notificaciones mostradas por OS. Permisos/consentimiento de avisos globales requieren política clara. No enviar ninguna prueba a toda producción.

## 9. Etapa 6 — Premium real

### T10. Paywall, compras y autorización

1. **Origen:** US21, compra real confirmada.
2. **Actual:** `Catalog` contiene premium/premiumHasta; `perfilEditable` excluye privilegios. No hay compra verificable completa.
3. **Objetivo:** compra sandbox de extremo a extremo, estado efectivo backend y beneficios autorizados; producción deshabilitada hasta condiciones comerciales confirmadas.
4. **Archivos:** `PawLife/lib/views/profile_screen.dart`, modelos/repositorio/Auth; **NUEVOS** `lib/views/premium_screen.dart`, `lib/services/purchase_service.dart`; pubspec después de validar SDK compatible; `apipaw/api/index.php`; **NUEVOS** `src/Billing/{BillingService,EntitlementService,GooglePlayProvider,AppleProvider,WebProvider}.php`. WebProvider es interfaz hasta elegir proveedor, no checkout simulado presentado como real.
5. **Cambios:** Android distribuido por Play usa Play Billing; iOS App Store usa IAP/StoreKit; web checkout alojado por proveedor aprobado según país/cuenta/medios/renovación. UI consume precio/duración reales del canal y catálogo aprobado: comparar gratis/Premium, comprar, pendiente, estado, restaurar y gestionar/cancelar mediante tienda/proveedor. Sin precio inventado ni bloquear cuidados gratuitos. API verifica compra remotamente y vínculo del comprador; conceder solo después. Acknowledge/completar transacción según plataforma tras verificación durable; reconciliar compras pendientes al reabrir y mediante cron/eventos.
6. **API NUEVA:** GET `/api/premium/products?platform=...` catálogo habilitado; GET `/api/me/premium` → `{activo,fuentes,beneficios,venceEn,estado,gestionUrl?}`; POST `/api/me/purchases/verify` `{provider,productId,evidence,solicitudId}` → estado verificado o pendiente; POST `/api/me/purchases/restore` valida compras del canal, no activa por petición. POST web checkout solo tras elegir proveedor. Webhooks separados `/api/internal/billing/{provider}` con autenticación/firma del proveedor; NO usar Firebase ID token como prueba de webhook.
7. **Datos:** **NUEVOS** `subscriptions`, `purchaseEvents`, `billingJobs`; referencias mínimas, ownerId, provider, environment, producto, estado normalizado, periodo, autoRenew, providerUpdatedAt y evento único. Tokens necesarios para consultar proveedor cifrados con manejo estándar y clave fuera de DB; no claves embebidas ni tarjetas. `users.premium/premiumHasta` es proyección de compatibilidad, no fuente autoritativa.
8. **Autorización/validación:** verificar package/bundle/producto/environment y titular; bind `obfuscatedAccountId`/`appAccountToken` según canal, no aceptar compra de otro UID ni transferir por restore arbitrario. Eventos con reserva atómica y payload hash; verificación firma/JWS/audience según proveedor y consulta del estado actual, no último webhook recibido. Reordenamiento de eventos no reactiva compra revocada. Beneficios protegidos en cada endpoint, no solo Flutter.
9. **Dependencias:** T05/06/07; catálogo y beneficios aprobados, países, entidad comercial, cuentas tienda, identificadores reales, credenciales sandbox, configuración de eventos. Eventos Android pueden requerir Pub/Sub del proveedor: dependencia específica de pagos a aprobar, no asumir que Cloudflare lo reemplaza. Reconciliación periódica cubre eventos perdidos.
10. **Pruebas:** aprobado/pendiente/rechazado/cancelación del diálogo; renovación, cancelación con acceso hasta vencimiento válido, expiración, gracia/on-hold según estado real del canal, refund/revoke, restore, compra ligada a otro usuario, duplicados y eventos desordenados, caída durante acknowledge, devolución tras cuenta borrada; sandbox por canal.
11. **Aceptación:** compra real sandbox cambia acceso en servidor y resiste reinstalación/restauración; revocación expira beneficios correctamente; comprobante falso/redirect no activa nada; gratuito mantiene funciones actuales. Entrega no completa mientras no haya beneficio real aprobado e implementado.
12. **Riesgos/decisiones:** confirmar beneficios (PDF veterinario/estadísticas solo candidatos), precio/moneda/periodo, canales y costos de publicación. Publicar y cobrar en todas las tiendas no puede prometerse gratis. Hosting comercial y cuotas requieren revisar plan contratado antes de cobros. No implementar un proveedor web sin decisión; no activar producción automáticamente al pasar sandbox.

### T11. Concesión manual compacta

1. **Origen:** Premium desde panel.
2. **Actual:** búsqueda exacta de usuario existente y campos premium; no sistema de grants separado.
3. **Objetivo:** consultar/conceder/vencer/revocar acceso manual sin afectar compras.
4. **Archivos:** `apipaw/panel/{index.html,app.js}`, NotificationAdmin/router; EntitlementService de T10; **NUEVO** `src/Billing/ManualGrantService.php`.
5. **Cambios:** sección compacta con correo exacto, estado pagado/manual, fecha de vencimiento y acción confirmada. No directorio gigante. Reutilizar patrón de búsqueda, creando capacidad separada `premium.manage`; no otorgarla automáticamente a todo administrador de avisos.
6. **API NUEVA:** GET `/api/admin/premium-users?email=...` búsqueda mínima; GET `/api/admin/users/{uid}/premium`; POST `/api/admin/users/{uid}/premium-grants` `{hasta,motivo,solicitudId}`; DELETE `/api/admin/users/{uid}/premium-grants/{id}` con intención estable. Devolver estado efectivo recalculado, no booleano supuesto.
7. **Datos:** **NUEVOS** `premiumGrants` con actor/uid/createdAt/endsAt/revokedAt/motivo corto; auditoría mínima. Entitlement = unión de fuentes pagadas válidas y concesiones manuales activas. Revocar grant no toca subscription.
8. **Autorización/validación:** permiso backend específico, reauth para conceder/revocar, vencimiento futuro obligatorio, límite de duración definido por equipo, motivo sin datos privados. Buscar por correo con rate limit, sin enumeración pública.
9. **Dependencias:** núcleo EntitlementService; puede implementarse antes que adaptadores de pago una vez definido el modelo de fuentes, sin fingir compra. Complejidad media; UI pequeña, aislamiento de fuentes obligatorio.
10. **Pruebas:** admin sin capacidad, usuario normal, fecha inválida, grant expirado, doble envío, revocar manual con suscripción activa, concurrente renovación/expiración, cuenta borrada, auditoría actor correcto.
11. **Aceptación:** efecto inmediato backend; expiración verificada al consultar y al usar beneficio aunque cron no corra; revocación no corta acceso comprado.
12. **Riesgos/decisiones:** responsables habilitados y duración máxima; valores legacy premium requieren inventario/reconciliación, no asumir que son compras ni borrarlos en masa.

## 10. Etapa 8 — Preferencias y requisito condicionado

### T12. Idiomas solo en Flutter

1. **Origen:** US28; español, inglés y portugués, solo app.
2. **Actual:** español fijo y textos hardcoded; delegates Material presentes.
3. **Objetivo:** traducción completa funcional y persistente, no solo menú.
4. **Archivos:** `PawLife/lib/main.dart`, `lib/views`, servicios con mensajes y modelos de UI; **NUEVOS** `lib/l10n/app_{es,en,pt}.arb`, `l10n.yaml`, `lib/services/preferences_service.dart`; pubspec; API preferencias y generación de recordatorios.
5. **Cambios:** gen-l10n, placeholders/plurales, fechas/unidades locales; fallback español. No traducir nombres/notas del usuario. Preferencia por usuario, fallback local para antes de login; no filtrar preferencia de otro UID. Recordatorios automáticos se generan mediante clave/args y locale actual; mensaje escrito por administrador conserva original.
6. **API:** PATCH `/api/me/preferences` propuesto `{idioma:es|en|pt}`; errores con code estable. Panel sin traducción nueva.
7. **Datos:** preferencias versionadas; mensajes antiguos compatibles como texto literal; nuevos templates con identificadores, sin guardar tres copias de cada aviso.
8. **Autorización/validación:** enum backend, solo propio usuario; placeholders escapados; no aceptar idioma arbitrario como ruta/plantilla.
9. **Dependencias:** T06 preferencias, T03 errores y pruebas de notificaciones; revisión humana portugués/inglés.
10. **Pruebas:** todas las pantallas/validaciones/diálogos/notificaciones, plural, cadenas largas, coma decimal, reabrir/cambiar cuenta/sin red, selección con sesión y sin sesión.
11. **Aceptación:** ningún flujo acordado queda parcialmente en español excepto contenido del usuario; elección persiste y no altera datos ni unidades almacenadas.
12. **Riesgos/decisiones:** dialecto portugués por confirmar; no usar traducción automática como validación lingüística final.

### T13. Claro, oscuro y sistema

1. **Origen:** US29, sin rediseño estético general.
2. **Actual:** un ThemeData y colores fijos; `AppColors` centraliza parte de la paleta.
3. **Objetivo:** apariencia legible en todos los estados manteniendo identidad/layout.
4. **Archivos:** `PawLife/lib/main.dart`, `lib/theme/app_colors.dart`, vistas afectadas; **NUEVO** `lib/theme/app_theme.dart`; PreferencesService T12.
5. **Cambios:** ThemeMode light/dark/system, roles semánticos de ColorScheme/ThemeExtension y sustitución focalizada de hardcodes. Mantener verdes #12352A/#5CC58F como identidad con variantes legibles. No invertir imágenes ni asumir tiles de mapa oscuros sin proveedor compatible.
6. **API:** PATCH `/api/me/preferences` `{apariencia:claro|oscuro|sistema}`; no afecta panel.
7. **Datos:** preferencia usuario/local, default sistema propuesto; migración sin pérdida.
8. **Autorización/validación:** enum; estados de error no dependen solo de color; contraste medido, foco/tap targets y lectura asistida.
9. **Dependencias:** preferencias; revisar código visual existente, no aplicar cambios globales mecánicos.
10. **Pruebas:** golden/render en ambos temas, sistema cambia en ejecución, teclado/dialog/date picker/snackbar/carga/vacío, texto 200%, teléfonos estrechos/tablet y lector de pantalla.
11. **Aceptación:** todas las pantallas y controles utilizables; preferencia restaurada; sin rebranding ni cambios al panel.
12. **Riesgos/decisiones:** aprobar default si se prefiere claro; mapa conserva estilo autorizado aunque marco sea oscuro.

### T14. Calorías: bloqueado por validación, no por UI

1. **Origen:** US27, inicialmente fuera de alcance y ahora condicionado.
2. **Actual:** no modelo validado identificado.
3. **Objetivo:** incorporar solo una estimación respaldada y aplicable a especies/datos reales.
4. **Archivos:** futuros cambios limitados a modelos/peso/resumen/servicio de cálculo; **NUEVO** `docs/CALORIE_MODEL_VALIDATION.md` antes de cualquier código.
5. **Cambios:** documentar fuente veterinaria, población/especie, unidades, rango de validez, incertidumbre y variables requeridas. No usar fórmula de humano ni requerimiento energético diario como gasto del paseo.
6. **API:** ninguno autorizado hasta definir modelo. Si se aprueba, cálculo servidor versionado o verificación equivalente; nunca aceptar cifra cliente como dato clínico cierto.
7. **Datos:** versión de modelo/inputs/fecha y estimación, solo si aprobados. No inventar masa corporal faltante.
8. **Autorización/validación:** propietario, datos vigentes, rangos del modelo; no consejo clínico automático.
9. **Dependencias:** aprobación veterinaria, T02 peso y calidad GPS.
10. **Pruebas:** casos de referencia del modelo, unidades/extremos/faltantes; comparación por profesional.
11. **Aceptación:** aprobación explícita y ejemplos reproducibles; mientras tanto requisito marcado condicionado, sin número ficticio.
12. **Riesgos/decisiones:** puede quedar fuera de entrega del profesor si no existe validación; comunicarlo claramente.

## 11. Etapa 9 — Integración y distribución

### T15. Cierre comprobable

1. **Origen:** RNF01–04, E2E, firma Android y todos los requisitos anteriores.
2. **Actual:** tests PHP/JS existentes; pruebas físicas pendientes; Android release firma debug.
3. **Objetivo:** matriz de evidencia por plataforma y release reproducible, sin decir «100%» por compilar.
4. **Archivos:** suites existentes `apipaw/tests`, `PawLife/test`; **NUEVOS** `PawLife/integration_test`, fixtures/scripts de pruebas y `docs/RELEASE_CHECKLIST.md`; `PawLife/android/app/build.gradle.kts`, configuración de plataforma y CI existente si aplica.
5. **Cambios:** keystore de distribución fuera de Git y CI secret; builds fallan si falta firma release en lugar de caer a debug; SHA OAuth y package correctos. iOS requiere entorno de firma autorizado; web no se declara equivalente a GPS/background móvil. Configurar entorno sandbox sin producción por defecto. Documentar restauración de backup y disponibilidad observada, sin SLA inventado.
6. **API:** probar contratos actuales y nuevos, errores y compatibilidad entre versiones; ningún endpoint nuevo solo para facilitar tests en producción.
7. **Datos:** fixtures sintéticos, usuarios A/B/admin-avisos/admin-premium, casos legacy; cleanup solo del sandbox identificado.
8. **Autorización/validación:** pruebas negativas obligatorias; revisar que documentos internos no sean accesibles desde SDK, logs ni panel. Permisos cámara/ubicación/notificaciones solicitados cuando corresponde, con alternativa al rechazo.
9. **Dependencias:** tareas implementadas y decisiones aprobadas; teléfono y cuentas sandbox. Sin ellos registrar pendiente, no simular aceptación humana.
10. **Pruebas:** matriz siguiente, más regresión de alta/login/cuidado/paseo/tareas/perfil. Navegador del panel con teclado, responsive y sesión expirada. Sandbox pagos reales del proveedor, nunca tarjetas/cuentas de producción.
11. **Aceptación:** reporte con versión, entorno, caso, esperado, observado, evidencia y pendientes; build firmado reproducible; ninguna tarea cerrada solo por tener archivo/ruta.
12. **Riesgos/decisiones:** stores, presupuesto de publicación y compatibilidad iOS real deben verificarse. No hacer push/deploy sin autorización específica de esa entrega.

| Área | Casos mínimos de aceptación futura |
|---|---|
| Auth/aislamiento | Sin token, expirado, revocado, deshabilitado, A→B en cada recurso y cursor, rol admin retirado |
| GPS/PNG | Paseo físico, bloqueo/reanudación, pérdida GPS, mapa/foto legacy, archivo PNG abierto y share sheet cancelado |
| Cuidados/peso | Crear/editar/eliminar según capacidad, historial, recordatorios asociados sin duplicación, persistencia al reiniciar |
| Privacidad | Todas las categorías exportadas, correo corregido verificado, consentimiento retirado, solicitud manual con atención |
| Eliminación | Fallo en cada etapa, cuenta grande, concurrencia, retry, Auth ausente, retención y verificación final |
| Moderación | es/pt/en, contexto veterinario, ambiguo/error, API directa, imágenes inválidas, no pérdida de borrador |
| Push/global | Seleccionados sin cambios, todos paginados, duplicados/invalid tokens, timeout incierto, revoked admin, permisos teléfono |
| Compras | Aprobado/pendiente/rechazado, restore, renovación/cancelación/expiración/refund/revoke, webhook duplicado/desordenado |
| Premium manual | Vencimiento/revocar; mantiene acceso pagado; usuario sin capacidad no eleva privilegios |
| UI Flutter/panel | Carga/vacío/error/offline, texto 200%, es/en/pt Flutter, temas, mobile/desktop, teclado/lector |
| Recuperación | Backup legible/restaurado sandbox, secretos separados, cuentas borradas no resucitan, medición RPO/RTO |

## 12. Etapa 10 — Propuestas visuales, sin implementación

### T16. Auditoría y prototipos separados

1. **Origen:** consigna visual, preservar Flutter y referencias FIX/KINORIA/Cuberto/Lusion/Nike.
2. **Actual:** NunitoSans, verdes #12352A/#5CC58F, fotografía de mascotas, navegación inferior y formularios; colores repetidos. No se renderizó la app actual en esta auditoría, por lo que no se asignan puntuaciones ni defectos de píxeles supuestos.
3. **Objetivo:** propuestas concretas para mejorar jerarquía/uso sin convertir la app en campaña ni implementar un rediseño.
4. **Archivos de referencia:** `lib/views/{home_screen,pet_detail_screen,route_screen,care_form_screen,profile_screen,tasks_screen}.dart`, `widgets/pawlife_bottom_nav.dart`, `theme/app_colors.dart`; **NUEVOS solo si se elaboran** mockups en `docs/design-proposals/`.
5. **Propuestas:** detalle de mascota con foto y nombre protagonistas, métricas de peso/paseo secundarias y acceso claro a historial; cuidado con un CTA por estado y error junto al campo; paseo con métricas legibles sobre mapa y acciones separadas «Capturar mapa»/«Compartir resumen»; Perfil organiza cuenta/privacidad/preferencias sin tarjetas redundantes. Motion breve ligado a seleccionar mascota, guardar y completar tarea, con reducción de movimiento. Conservar paleta, ilustraciones y estructura del equipo.
6. **API:** ninguna modificación visual autorizada; maquetas usan fixtures explícitos.
7. **Datos:** ningún dato personal real en capturas compartidas; mocks etiquetados.
8. **Autorización/validación:** etiquetas accesibles, orden de lectura, controles ≥48 dp cuando corresponda, contraste medido y texto escalable; no esconder controles por estética.
9. **Dependencias:** render actual en teléfono/emulador; para trabajo visual sustancial leer biblioteca creativa global y seleccionar referencias pertinentes. No reabrir veinte sitios para un ajuste funcional.
10. **Pruebas:** comparación lado a lado antes/propuesta con carga/vacío/error/listas largas, 320–430 dp/tablet, texto 200%, temas y idiomas; revisar motion real, no solo screenshot.
11. **Aceptación:** propuesta separada aprobada por usuario antes de tocar widgets; explicación de beneficio y costo por cambio. No presentar esta lista como mockup renderizado.
12. **Riesgos/decisiones:** intensidad artística debe favorecer tareas de cuidado. US29 autoriza tema funcional, no cambios arbitrarios de composición.

## 13. Secuencia, decisiones y sugerencias

Orden de implementación: T01–T03 → T05 → T06–T07 → T04 (requiere preferencias/jobs) → T08 → T09 → T10 → T11 → T12–T14 → T15. T16 solo diagnóstico/propuesta. T11 puede adelantarse después del núcleo de derechos Premium, sin proveedor de pago, si el equipo lo prioriza. No desarrollar publicidad US20. La interfaz de reloj se planifica aparte (`PawLife/docs/PLAN_PENDIENTES_Y_RELOJ.md`).

Decisiones que requieren respuesta antes de cerrar tareas dependientes:

1. Beneficios concretos Premium, precio/moneda/periodos, país/entidad, canales de publicación y cuentas disponibles. Aprobar costos y revisar elegibilidad del hosting comercial antes de cobrar.
2. Proveedor web y de moderación, presupuesto, retención del proveedor y transferencias. Vision exige billing; no activarlo por inercia. El adaptador se puede preparar, pero su aceptación real queda pendiente.
3. Responsable/contacto de privacidad, bases por finalidad, política de conservación y excepciones, procedimiento/tiempo legal de atención. Revisión LGPD y aplicabilidad Uruguay sin prometer dictamen jurídico.
4. Intervalo de inactividad, preferencias de avisos globales, política de dispositivos; roles que conceden Premium y duración máxima.
5. Confirmar foto convencional adicional durante paseo; validación veterinaria US27; dialecto portugués y default de apariencia si difieren de propuestas.
6. Teléfono, cuentas sandbox, firma de distribución y responsable de backups. No solicitar contraseñas por chat.

| Sugerencia adicional (no incorporada sin aprobación) | Prioridad/beneficio | Dificultad/riesgo | Dependencias |
|---|---|---|---|
| Gráfico de evolución de peso | Media; leer tendencia más rápido | Baja-media; no diagnosticar | Historial T02 |
| Foto convencional además del mapa | Media; recuperar recuerdo del paseo | Media; permisos y moderación | T01/T08 y confirmación |
| Aviso operacional por job atascado | Media; detectar fallos a tiempo | Baja-media; evitar datos privados en alertas | T05, canal/responsable; no centro nuevo de avisos del panel |
| PDF veterinario | Candidato comercial, no requisito aprobado | Media-alta; privacidad y exactitud | Beneficios Premium y exportación |
| Estadísticas avanzadas | Candidato comercial, no requisito aprobado | Media; datos insuficientes/interpretación | Definir métricas útiles y límites |

### Fuentes específicas y límites

No se repitió investigación general. Se consultó la incompatibilidad concreta de billing de [Cloud Vision](https://docs.cloud.google.com/vision/docs/setup), que requiere habilitar facturación. Para implementar verificación de pagos, seguir la [guía oficial de seguridad de Play Billing](https://developer.android.com/google/play/billing/security) y [App Store Server Notifications](https://developer.apple.com/documentation/appstoreservernotifications); validar detalles del SDK y canal elegido al implementarlo, sin asumir que la documentación Apple no renderizada por el lector web quedó auditada íntegramente.

Referencias legales aportadas por la consigna, para validación del responsable: [derechos de titulares ANPD](https://www.gov.br/anpd/pt-br/assuntos/titular-de-dados-1/direito-dos-titulares), [LGPD 13.709](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm), [Ley 18.331 Uruguay](https://www.impo.com.uy/bases/leyes/18331-2008). Este plan aporta mecanismos técnicos; no constituye certificación legal.

## 14. Prompt breve para el modelo implementador

> Trabajá sobre PawLife y apipaw, leyendo primero instrucciones locales, estado Git y `apipaw/docs/PAWLIFE_IMPLEMENTATION_PLAN.md`. Compará HEAD con las bases documentadas y preservá cambios del equipo. Implementá una tarea pequeña por vez en el orden y contratos del plan; no reconstruyas funciones existentes ni inventes decisiones comerciales. Empezá por T01–T03 y bloqueos de T05. Marcá archivos/endpoints nuevos como tales y mantené compatibilidad móvil/API. No rediseñes Flutter ni cambies los avisos actuales de envío seleccionado. No habilites pagos, billing o proveedores de moderación sin decisión; no uses producción para pruebas destructivas ni notificaciones. Ejecutá pruebas proporcionales, inspeccioná los renders cuando cambie UI y registrá evidencia/pending por requisito. Sin teléfono, OAuth o sandbox real, no cierres esas verificaciones. No hagas push ni deploy sin autorización explícita. Detenete solo en la parte que depende de una decisión pendiente y continuá lo independiente.
