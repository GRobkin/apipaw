import test from 'node:test';
import assert from 'node:assert/strict';
import { createBatch, sendBatch } from '../panel/notification-batch.mjs';

const users = [{ uid: 'a', label: 'Cuenta A' }, { uid: 'b', label: 'Cuenta B' }];

test('solo envía a las personas seleccionadas, sin duplicar UIDs', async () => {
  let id = 0;
  const batch = createBatch([...users, users[0]], 'Aviso', () => `request-${++id}`);
  const calls = [];
  await sendBatch(batch, async (body) => {
    calls.push(body);
    return { aceptadas: 1 };
  });
  assert.deepEqual(calls.map((body) => body.userId), ['a', 'b']);
  assert.equal(new Set(calls.map((body) => body.solicitudId)).size, 2);
  assert.ok(calls.every((body) => body.mensaje === 'Aviso'));
});

test('un fallo parcial no omite a los demás y el reintento conserva sus IDs', async () => {
  let id = 0;
  const batch = createBatch(users, 'Aviso', () => `request-${++id}`);
  const first = await sendBatch(batch, async (body) => {
    if (body.userId === 'a') throw new Error('Sin confirmar');
    return { aceptadas: 2, dispositivos: 2 };
  });
  assert.equal(first[0].error, 'Sin confirmar');
  assert.equal(first[1].result.aceptadas, 2);
  const retry = [];
  await sendBatch(batch, async (body) => { retry.push(body.solicitudId); return {}; });
  assert.deepEqual(retry, ['request-1', 'request-2']);
});

test('cambiar la sesión detiene los destinatarios que todavía no se enviaron', async () => {
  const batch = createBatch(users, 'Aviso', () => 'request');
  let active = true;
  const called = [];
  const results = await sendBatch(batch, async (body) => {
    called.push(body.userId);
    active = false;
    return { aceptadas: 1 };
  }, () => active);
  assert.deepEqual(called, ['a']);
  assert.deepEqual(results, []);
});
