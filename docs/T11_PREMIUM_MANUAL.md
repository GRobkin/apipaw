# T11 — Premium manual (local, sin desplegar)

Estado: implementado y probado con un doble de Firestore en memoria (`tests/premium_grants_test.php`). **No probado contra Firestore real ni desplegado. No hay panel ni app que lo use todavía.** No hay cobros, compras ni proveedor de pago.

## Modelo
- `EntitlementService::effective(uid)`: estado recalculado en cada consulta como unión de fuentes: `manual` (concesiones `premiumGrants` no revocadas ni vencidas) y `pago` (`subscriptions`, que escribirá T10: hoy solo se leen). El vencimiento se comprueba al consultar: no depende de ningún cron.
- `users.premium` / `premiumHasta` quedan como **proyección de compatibilidad** que el servidor mantiene tras conceder/revocar; **no son fuente**. Los valores heredados se informan en `legacy` pero no conceden acceso (pueden no corresponder a ninguna compra); no se borran ni se reescriben en masa.
- `ManualGrantService`: conceder y revocar. Cada acción escribe concesión + auditoría (actor, cuenta, acción, concesión) en **una** escritura atómica. Revocar no toca suscripciones.

## API (todas requieren sesión)
- `GET /api/admin/premium-users?email=` (exacto, con límite de consultas).
- `GET /api/admin/users/{uid}/premium` → estado efectivo + últimas 20 concesiones.
- `POST /api/admin/users/{uid}/premium-grants` `{hasta, motivo, solicitudId}` → 201. Idempotente por (admin, solicitudId); mismos ID con otros datos → 409.
- `DELETE /api/admin/users/{uid}/premium-grants/{id}` → idempotente.
- `GET /api/me/premium` → `{activo, estado, venceEn, fuentes}` de la propia cuenta.

## Autorización
- Capacidad propia: `configuracion/premium.administradores` (uids). **No** se hereda de administrar avisos.
- Conceder y revocar exigen autenticación reciente (≤ 5 min) verificada en el servidor.
- Vencimiento obligatorio, futuro y con tope: `configuracion/premium.duracionMaximaDias` (1–365) o **30 días si no está configurado**. Motivo ≤ 140 caracteres, sin correos ni números largos.
- `premiumGrants` y `premiumAudit` pertenecen a la cuenta (`AccountLifecycle::OWNED`): se bloquean escrituras si se está borrando y se eliminan con ella.

## Pendiente / decisiones
- Crear `configuracion/premium` con los responsables y el tope: **decisión del equipo**. Sin ese documento nadie puede gestionar Premium.
- Pantalla en la app: no hecha.
- Exportación de datos propios: aún no incluye `premiumGrants`.
- Qué beneficios autoriza Premium (T10) y qué hacer con los valores `legacy`: inventario y decisión de producto.
- Índice para `premiumGrants` por (userId, creadoEn desc) y `subscriptions` por (userId, creadoEn desc): confirmar en Firestore.

## Panel (sección «Premium»)
- `GET /api/admin/session` ahora informa `{avisos, premium}`: el panel muestra cada módulo solo si la cuenta tiene esa capacidad (los endpoints la vuelven a comprobar). Una cuenta con solo `premium.manage` puede entrar al panel sin poder enviar avisos.
- Sección compacta: correo exacto → estado efectivo (manual / pagado / valor heredado sin respaldo), lista de concesiones con «Revocar», formulario de días y motivo, y un diálogo de confirmación para cada acción. El estado mostrado siempre lo calcula el servidor.
- Si el inicio de sesión tiene más de 5 minutos, el servidor responde `REAUTHENTICATION_REQUIRED` y el panel pide ingresar de nuevo (no hay reautenticación en el lugar).
- Verificado en un navegador con una API **simulada** (no la real) y un stub de Firebase: búsqueda, conceder (formato de fecha validado con la misma expresión del servidor), revocar, error de reautenticación y vista de teléfono sin desborde. Tests Node: `tests/premium_admin_test.mjs`. No probado con Firebase ni Firestore reales.
