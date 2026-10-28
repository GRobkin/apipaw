import test from 'node:test';
import assert from 'node:assert/strict';
import { toAtom, grantBody, describeState, describeGrant, friendlyError } from '../panel/premium-admin.mjs';

const now = new Date('2026-10-20T10:00:00.000Z');

test('la fecha viaja en el formato que exige el servidor (sin Z ni milisegundos)', () => {
  assert.equal(toAtom(now), '2026-10-20T10:00:00+00:00');
  const body = grantBody({ days: 7, motivo: ' Prueba interna ', solicitudId: 'id-1' }, now);
  assert.deepEqual(body, { hasta: '2026-10-27T10:00:00+00:00', motivo: 'Prueba interna', solicitudId: 'id-1' });
  assert.match(body.hasta, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/);
});

test('rechaza días inválidos y motivos con datos personales antes de llamar a la API', () => {
  for (const days of [0, -1, 1.5, '', 'abc', null]) {
    assert.throws(() => grantBody({ days, motivo: 'ok', solicitudId: 'x' }, now), /días/);
  }
  assert.throws(() => grantBody({ days: 3, motivo: '   ', solicitudId: 'x' }, now), /motivo/);
  assert.throws(() => grantBody({ days: 3, motivo: 'a'.repeat(141), solicitudId: 'x' }, now), /motivo/);
  assert.throws(() => grantBody({ days: 3, motivo: 'para ana@example.test', solicitudId: 'x' }, now), /correos/);
  assert.throws(() => grantBody({ days: 3, motivo: 'doc 12345678', solicitudId: 'x' }, now), /correos/);
});

test('el estado distingue manual, pagado y valores heredados sin respaldo', () => {
  const none = { activo: false, venceEn: null, fuentes: { manual: { activa: false }, pago: { activa: false } }, legacy: { premium: true } };
  const d = describeState(none);
  assert.equal(d.headline, 'Sin Premium activo');
  assert.ok(d.lines.some((l) => /heredado sin respaldo/.test(l)));
  const both = { activo: true, venceEn: '2026-11-01T00:00:00+00:00',
    fuentes: { manual: { activa: false, venceEn: null }, pago: { activa: true, venceEn: '2026-11-01T00:00:00+00:00' } }, legacy: { premium: false } };
  const e = describeState(both);
  assert.match(e.headline, /^Premium activo hasta/);
  assert.match(e.lines[0], /sin concesión vigente/);
  assert.match(e.lines[1], /Pagado: activo hasta/);
});

test('solo las concesiones activas se pueden revocar', () => {
  const base = { terminaEn: '2026-11-01T00:00:00+00:00', revocadoEn: '2026-10-21T00:00:00+00:00', motivo: 'Prueba' };
  assert.equal(describeGrant({ ...base, estado: 'activa' }).revocable, true);
  assert.equal(describeGrant({ ...base, estado: 'vencida' }).revocable, false);
  const revoked = describeGrant({ ...base, estado: 'revocada' });
  assert.equal(revoked.revocable, false);
  assert.match(revoked.text, /^Revocada el .+ · Prueba$/);
  assert.doesNotMatch(revoked.text, /Revocada · revocada/);
});

test('la reautenticación se explica en lugar de mostrar un error técnico', () => {
  assert.match(friendlyError({ code: 'REAUTHENTICATION_REQUIRED', message: 'x' }), /ingresá de nuevo/);
  assert.equal(friendlyError(new Error('Motivo inválido')), 'Motivo inválido');
  assert.equal(friendlyError(null), 'No se pudo completar la operación.');
});
