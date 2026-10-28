# T09 — Enviar a todos (local, sin desplegar)

Estado: implementado y probado con almacén y emisor simulados (`tests/global_notification_test.php`, `tests/global_job_test.mjs`). **No probado contra Firestore/FCM reales, ni en un navegador, ni desplegado. No se envió nada a producción.**

## Cómo funciona
- Panel: selector «Personas seleccionadas / Todas las personas». El envío a personas elegidas **no cambió** (mismo endpoint, mismos IDs, mismo límite de 3 dispositivos por persona).
- `POST /api/admin/notifications/global` `{mensaje, solicitudId, confirmarTodos:true}` → 202 `{id, estado, contadores}`. Exige permiso de administrador y la confirmación explícita. Idempotente por (admin, solicitudId); otro texto con el mismo ID → 409; solo un trabajo `en_proceso` a la vez (409).
- `GET /api/admin/notifications/global/{id}` → estado y contadores (sin tokens ni perfiles).
- `POST /api/internal/process-notification-jobs` (firmada, atada a la ruta): avanza el trabajo en tramos de hasta 25 cuentas / 100 envíos. El Worker la llama en cada cron (presupuesto propio, no frena los recordatorios). Aún hay que redesplegar el Worker.
- Audiencia congelada al crear (`corte`): quien se registra después no entra. Recorrido por ID de usuario con cursor persistido junto a los contadores.
- Un registro por dispositivo y trabajo (`notificationJobItems`, ID = trabajo + hash del token): un token repetido en varias cuentas recibe un solo envío.
- Antes de cada envío se revalida cuenta y preferencia de avisos del equipo; en cada tramo se revalida el permiso del administrador (si se revoca, el trabajo queda `detenido`).
- Resultados: `aceptadas` (aceptadas por FCM; **no** significa entregadas), `fallidas` (FCM respondió que no), `inciertas` (sin respuesta, o corte entre reservar y registrar; **no se reintentan** para no duplicar), omitidas por motivo. Solo un código verificable de token inválido (`UNREGISTERED`/`NOT_FOUND`) desactiva el dispositivo.
- Política por cuenta explícita: hasta 5 dispositivos; el resto se cuenta como omitido (`limite_por_cuenta`), no se recorta en silencio.

## Pendiente / límites
- Sin moderación (T08) ni política de consentimiento para avisos globales: decisión de producto.
- Un envío `incierto` requiere una política de recuperación (hoy: ninguna, solo se informa).
- No hay forma de cancelar un trabajo en curso desde el panel (se detiene solo si se revoca el permiso).
- Medir lecturas/escrituras y cuotas de Firestore antes de usar con volumen real (≈ 1 lectura de usuario + 1 de dispositivos + 2–3 escrituras por dispositivo).
- Confirmar en Firestore el orden por `__name__` con `startAt` y la consulta de dispositivos (userId, actualizadoEn desc): índice compuesto necesario como en el envío dirigido.
- La interfaz del panel no se abrió en un navegador.
