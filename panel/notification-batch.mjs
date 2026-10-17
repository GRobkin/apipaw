export function createBatch(recipients, mensaje, uuid = () => crypto.randomUUID()) {
  const unique = [...new Map(recipients.map((user) => [user.uid, user])).values()];
  return unique.map((user) => ({
    label: user.label,
    body: { userId: user.uid, mensaje, solicitudId: uuid() },
  }));
}

// Cada persona conserva su solicitudId: reintentar no duplica los avisos que
// el servidor ya aceptó, incluso si se perdió la respuesta HTTP.
export async function sendBatch(batch, send, active = () => true, progress = () => {}) {
  const results = [];
  for (const item of batch) {
    if (!active()) break;
    try {
      const result = await send(item.body);
      if (!active()) break;
      results.push({ label: item.label, result });
    } catch (error) {
      if (!active()) break;
      results.push({ label: item.label, error: error.message || 'Sin confirmar' });
    }
    progress(results.length, batch.length);
  }
  return results;
}
