import test from 'node:test';
import assert from 'node:assert/strict';
import { createGlobalRequest, runGlobal, summarizeGlobal } from '../panel/global-job.mjs';

const job = (estado, contadores = {}) => ({ id: 'global-abc', estado, contadores });

test('la solicitud lleva confirmación explícita e ID propio', () => {
  const request = createGlobalRequest('Aviso', () => 'uuid-1');
  assert.deepEqual(request, { mensaje: 'Aviso', solicitudId: 'uuid-1', confirmarTodos: true });
});

test('crea el trabajo y consulta hasta que termina', async () => {
  const calls = [];
  const states = [job('en_proceso'), job('en_proceso', { usuarios: 25 }), job('completado', { usuarios: 40 })];
  const progress = [];
  const final = await runGlobal(async (path, body) => {
    calls.push([path, body ? 'POST' : 'GET']);
    return states.shift();
  }, createGlobalRequest('Aviso', () => 'u'), { wait: async () => {}, onProgress: (j) => progress.push(j.estado) });
  assert.equal(final.estado, 'completado');
  assert.deepEqual(calls, [
    ['/api/admin/notifications/global', 'POST'],
    ['/api/admin/notifications/global/global-abc', 'GET'],
    ['/api/admin/notifications/global/global-abc', 'GET'],
  ]);
  assert.deepEqual(progress, ['en_proceso', 'en_proceso', 'completado']);
});

test('cambiar la sesión detiene la consulta sin crear nada nuevo', async () => {
  let posts = 0;
  let active = true;
  const result = await runGlobal(async () => { posts += 1; return job('en_proceso'); },
    createGlobalRequest('Aviso'), { wait: async () => { active = false; }, active: () => active });
  assert.equal(result, null);
  assert.equal(posts, 1);
});

test('fallos de consulta aislados se toleran; repetidos informan que el envío sigue', async () => {
  let n = 0;
  const flaky = await runGlobal(async (_path, body) => {
    if (body) return job('en_proceso');
    n += 1;
    if (n === 1) throw new Error('red');
    return job('completado');
  }, createGlobalRequest('Aviso'), { wait: async () => {} });
  assert.equal(flaky.estado, 'completado');
  await assert.rejects(runGlobal(async (_path, body) => {
    if (body) return job('en_proceso');
    throw new Error('red');
  }, createGlobalRequest('Aviso'), { wait: async () => {} }), /sigue en el servidor/);
});

test('el resumen nunca afirma entrega y separa incierto, fallido y omitido', () => {
  const text = summarizeGlobal(job('completado', {
    usuarios: 10, usuariosOmitidos: 2, dispositivosOmitidos: 1, aceptadas: 6, fallidas: 1, inciertas: 1,
  }));
  assert.match(text, /6 dispositivos aceptados por FCM/);
  assert.match(text, /1 fallidos/);
  assert.match(text, /1 con resultado incierto/);
  assert.match(text, /3 omitidos/);
  assert.doesNotMatch(text, /entregad/i);
  assert.match(summarizeGlobal({ ...job('detenido'), motivoDetencion: 'permiso_revocado' }), /se revocó el permiso/);
});
