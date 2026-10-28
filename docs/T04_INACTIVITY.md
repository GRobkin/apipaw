# T04 — Aviso por falta de paseos (local, sin desplegar)

Estado: implementado y probado con un almacén en memoria (`tests/inactivity_test.php`). **No probado contra Firestore real ni desplegado.**

## Qué hace
- Opt-in por persona: `PATCH /api/me/preferences` acepta `inactividad: {habilitada, intervaloHoras, incluirSinPaseos}` (parcial; se fusiona con lo guardado). Apagado por defecto.
- `POST /api/internal/inactivity-reminders` (firmada por el Worker, ruta atada a la firma) recorre a quienes lo habilitaron y, por mascota, crea **un** recordatorio `tipo=inactividad` por episodio.
- Episodio = desde el fin del último paseo (o, con `incluirSinPaseos`, desde el alta) hasta el siguiente paseo. El ID del recordatorio deriva de (usuario, mascota, origen), así que repetir o solapar el cron no duplica.
- Un paseo nuevo completa los avisos pendientes del episodio anterior; se revalida justo antes de crear por si apareció un paseo.
- El envío lo hace `PushDispatcher` en su siguiente ejecución (respeta `recordatoriosCuidados`, dispositivos, cuota).
- `?dryRun=1` cuenta sin escribir.

## Límites y decisiones pendientes
- Intervalo permitido 12–720 h (límite técnico); el valor por defecto de 72 h es solo sugerencia de la interfaz. **Decisión de producto pendiente.** No hay horas de descanso.
- El mensaje se genera en español: el servidor no conoce el idioma elegido en la app. Mejora pendiente: guardar el idioma como preferencia.
- Tope por ejecución: 200 usuarios y 50 avisos; sin paginación entre ejecuciones (si hubiera más, se atienden en las siguientes en el mismo orden, lo que podría dejar fuera a algunos).
- Consultas que dependen de índices: `paseos` (userId, mascotaId, fechaInicio desc) debería ser el mismo compuesto del historial; confirmar en producción. `users` por `preferencias.inactividad.habilitada` usa índice de campo único.
- Falta desplegar API y Worker (`wrangler deploy`) para que el cron la llame.
