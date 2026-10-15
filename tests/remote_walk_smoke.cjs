// Integración real: usa únicamente cuentas y paseos temporales propios.
const crypto = require('node:crypto');
const config = require('../panel/firebase-config.json');
const base = process.env.PAWLIFE_TEST_API || 'https://apipaw.vercel.app';
const runId = crypto.randomBytes(8).toString('hex');
const accounts = [];
async function call(path, token, method = 'GET', body, expected = 200) {
  const response = await fetch(base + path, { method,
    headers: { ...(token ? { Authorization: `Bearer ${token}` } : {}), 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined, signal: AbortSignal.timeout(65000) });
  const text = await response.text();
  const payload = text ? JSON.parse(text) : null;
  if (response.status !== expected) throw new Error(`${method} ${path.split('?')[0]}: esperado ${expected}, obtenido ${response.status} (${payload.error?.message || 'sin detalle'})`);
  return payload;
}
async function account(n) {
  const response = await fetch(`https://identitytoolkit.googleapis.com/v1/accounts:signUp?key=${config.apiKey}`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: `pawlife-walk-${runId}-${n}@example.invalid`, password: crypto.randomBytes(24).toString('base64url'), returnSecureToken: true }),
    signal: AbortSignal.timeout(25000),
  });
  const user = await response.json();
  if (!response.ok) throw new Error('No se pudo crear la cuenta temporal de prueba.');
  const result = { token: user.idToken, uid: user.localId };
  accounts.push(result);
  await call('/api/me', result.token);
  return result;
}
function expect(value, message) { if (!value) throw new Error(message); }
async function main() {
  try {
    await call('/api/health');
    await call('/api/admin/session', null, 'GET', undefined, 401);
    const a = await account(1);
    const b = await account(2);
    await call('/api/admin/session', a.token, 'GET', undefined, 403);
    await call('/api/admin/notifications', a.token, 'POST', { userId: b.uid, mensaje: 'No debe enviarse', solicitudId: crypto.randomUUID() }, 403);
    const petA = await call('/api/mascotas', a.token, 'POST', { nombre: 'Paseos temporales', especie: 'perro' }, 201);
    const petB = await call('/api/mascotas', b.token, 'POST', { nombre: 'Otra cuenta temporal', especie: 'gato' }, 201);
    const date = '2026-10-15T12:00:00Z';
    const walk = { fechaInicio: date, fechaFin: '2026-10-15T12:05:00Z', duracionSegundos: 300,
      distanciaMetros: 100, velocidadMaximaKmh: 3, ruta: [{ lat: -34.9, lng: -56.1, timestamp: date }] };
    for (let start = 0; start < 31; start += 4) {
      await Promise.all(Array.from({ length: Math.min(4, 31 - start) }, (_, i) => call(`/api/mascotas/${petA.id}/paseos`, a.token, 'POST',
        { id: `smoke_${runId}_${String(start + i).padStart(3, '0')}`, ...walk }, 201)));
    }
    const page1 = await call(`/api/mascotas/${petA.id}/historial-paseos`, a.token);
    expect(page1.items.length === 30 && page1.nextCursor, 'Primera página incorrecta.');
    const page2 = await call(`/api/mascotas/${petA.id}/historial-paseos?cursor=${encodeURIComponent(page1.nextCursor)}`, a.token);
    expect(page2.items.length === 1 && page2.nextCursor === null, 'Segunda página incorrecta.');
    expect(new Set([...page1.items, ...page2.items].map((item) => item.id)).size === 31, 'Se omitieron o duplicaron paseos con igual fecha.');
    await call(`/api/mascotas/${petA.id}/historial-paseos`, b.token, 'GET', undefined, 404);
    await call(`/api/mascotas/${petB.id}/historial-paseos?cursor=${encodeURIComponent(page1.nextCursor)}`, b.token, 'GET', undefined, 400);
    const erased = page2.items[0].id;
    const deleted = await fetch(`${base}/api/mascotas/${petA.id}/paseos/${erased}`, { method: 'DELETE', headers: { Authorization: `Bearer ${a.token}` } });
    expect(deleted.status === 204, 'No se pudo eliminar el paseo temporal.');
    const refreshed = await call(`/api/mascotas/${petA.id}/historial-paseos`, a.token);
    expect(refreshed.items.length === 30 && refreshed.nextCursor === null, 'El historial no reflejó el borrado.');
    console.log('OK remoto: 31 paseos paginados, empates de fecha, borrado, aislamiento entre cuentas y panel bloqueado a usuarios comunes. No se enviaron notificaciones.');
  } finally {
    const cleanup = await Promise.allSettled(accounts.map(async (user) => {
      await call('/api/me', user.token, 'DELETE', undefined, 204);
      await call('/api/dispositivos', user.token, 'GET', undefined, 401);
    }));
    if (cleanup.some((result) => result.status === 'rejected')) throw new Error('No se completó la limpieza de las cuentas temporales de esta ejecución.');
    console.log('Limpieza OK: cuentas y datos temporales eliminados; sesiones antiguas rechazadas.');
  }
}
main().catch((error) => { console.error(error.message); process.exitCode = 1; });
