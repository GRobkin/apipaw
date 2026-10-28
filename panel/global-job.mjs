// Envío a todas las personas: el servidor procesa el trabajo en lotes y el panel
// solo lo crea y consulta su progreso.

export function createGlobalRequest(mensaje, uuid = () => crypto.randomUUID()) {
  // La confirmación explícita viaja con la solicitud: el servidor la exige.
  // El solicitudId se conserva mientras el texto no cambie: repetir tras perder
  // la respuesta devuelve el mismo trabajo en lugar de crear otro.
  return { mensaje, solicitudId: uuid(), confirmarTodos: true };
}

export async function runGlobal(api, request, {
  active = () => true,
  onProgress = () => {},
  wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
  intervalMs = 5000,
  maxPollFailures = 3,
} = {}) {
  let job = await api('/api/admin/notifications/global', request);
  let failures = 0;
  while (active()) {
    onProgress(job);
    if (job.estado !== 'en_proceso') return job;
    await wait(intervalMs);
    if (!active()) break;
    try {
      job = await api(`/api/admin/notifications/global/${encodeURIComponent(job.id)}`);
      failures = 0;
    } catch (error) {
      failures += 1;
      if (failures >= maxPollFailures) {
        throw new Error(`No se pudo consultar el progreso. El envío sigue en el servidor (ID ${job.id}).`);
      }
    }
  }
  return null;
}

// "Aceptado por FCM" no es "entregado": no hay evidencia de entrega al teléfono.
export function summarizeGlobal(job) {
  const c = job.contadores ?? {};
  const omitted = (c.usuariosOmitidos ?? 0) + (c.dispositivosOmitidos ?? 0);
  const state = {
    en_proceso: 'En proceso',
    completado: 'Completado',
    detenido: job.motivoDetencion === 'permiso_revocado' ? 'Detenido: se revocó el permiso' : 'Detenido',
  }[job.estado] ?? job.estado;
  return [
    state,
    `${c.usuarios ?? 0} cuentas recorridas`,
    `${c.aceptadas ?? 0} dispositivos aceptados por FCM`,
    `${c.fallidas ?? 0} fallidos`,
    `${c.inciertas ?? 0} con resultado incierto (no se reintentan)`,
    `${omitted} omitidos`,
  ].join('\n');
}
