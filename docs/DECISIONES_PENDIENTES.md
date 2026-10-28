# Decisiones de producto pendientes — propuestas (28/10/2026)

Son **propuestas para que decidas**; no hay nada aplicado salvo lo indicado. Cada una dice qué hace hoy el código y qué cambiaría.

| Tema | Hoy en el código | Propuesta | Costo de cambiar |
|---|---|---|---|
| Intervalo de inactividad | Opt-in; 12–720 h; sugerencia 72 h; sin horas de descanso | Mantener opt-in, ofrecer 1/2/3/5/7/14/30 días (ya está en la app) y no avisar entre 22:00 y 08:00 en la hora de la persona (requiere guardar zona horaria) | Bajo–medio |
| Idioma del aviso de inactividad | **Resuelto**: el servidor usa `preferencias.idioma` (es/en/pt) y la app lo sincroniza | — | — |
| Consentimiento para avisos globales | Solo llegan a quien tiene `avisosEquipo` activado (por defecto activado para cuentas existentes) | Decidir si el valor por defecto debe ser opt-in explícito y mostrar el aviso al primer ingreso | Medio (política) |
| Moderación de avisos globales (T08) | Sin filtro; el panel exige confirmar el texto | Revisión humana: segundo administrador confirma un aviso global antes de iniciarse. Un proveedor automático exige facturación: no activarlo sin tu autorización | Medio |
| Envíos inciertos | No se reintentan; se cuentan aparte | Mantener la regla. Más adelante, un botón «reenviar a inciertos» que muestre cuántos son y pida confirmación | Medio |
| Beneficios de Premium (T10) | Ninguno autorizado todavía | Candidatos: informe veterinario en PDF y estadísticas avanzadas (ver plan). Precio, moneda, proveedor y país los fijás vos | Alto |
| Valores `premium` heredados | Se informan como `legacy` y **no** conceden acceso | Correr `tool/report-data-issues.cjs`-style inventario por usuario antes de decidir; no migrarlos en masa | Bajo |
| Calorías (T14) | La app dice «no disponible»; no hay fórmula | No mostrar cifras hasta tener un modelo validado por un veterinario. Mientras tanto, mantener el mensaje | — |
| Visitas al veterinario | Una tarjeta «Sin agendar» sin entidad detrás | Alcance mínimo: fecha, motivo corto y recordatorio; sin adjuntos ni historial clínico | Medio–alto |
| Tope de duración de Premium manual | 30 días si no hay configuración | Fijar `duracionMaximaDias` con `tool/configure-premium-admins.cjs` | Bajo |

## Cómo ejecutar lo que necesita tu cuenta (no lo corrí)
- `FIREBASE_TOOLS_ROOT=… node tool/configure-premium-admins.cjs correo@x.com --max-days 30` muestra qué haría (**simulación**); con `--apply` escribe `configuracion/premium`. Solo agrega responsables.
- `FIREBASE_TOOLS_ROOT=… node tool/report-data-issues.cjs` imprime, en solo lectura, los recordatorios sin `entidadId` y los dispositivos demo. No corrige ni inventa IDs.
- `GET /api/admin/diagnostics/photos` (con sesión de administrador) informa si el entorno desplegado procesa fotos (GD o el decodificador de Node). El runtime de Vercel PHP no publica GD; **hay que consultarlo tras desplegar**.
