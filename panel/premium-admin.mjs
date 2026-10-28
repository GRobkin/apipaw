// Premium manual en el panel: construcción de peticiones y textos.
// El estado efectivo lo calcula siempre el servidor; aquí no se decide nada.

/** El servidor exige ISO 8601 con desfase (`+00:00`), sin milisegundos ni `Z`. */
export function toAtom(date) {
  return date.toISOString().replace(/\.\d{3}Z$/, '+00:00');
}

export function grantBody({ days, motivo, solicitudId }, now = new Date()) {
  const n = Number(days);
  if (!Number.isInteger(n) || n < 1) throw new Error('Indicá una cantidad de días entera, mayor que cero.');
  const motivoLimpio = String(motivo ?? '').trim();
  if (!motivoLimpio || Array.from(motivoLimpio).length > 140) throw new Error('Escribí un motivo breve (hasta 140 caracteres).');
  if (motivoLimpio.includes('@') || /\d{6,}/.test(motivoLimpio)) {
    throw new Error('El motivo no debe incluir correos ni números de identificación.');
  }
  return { hasta: toAtom(new Date(now.getTime() + n * 86400000)), motivo: motivoLimpio, solicitudId };
}

const formatDate = (iso) => new Date(iso).toLocaleString('es', { dateStyle: 'medium', timeStyle: 'short' });

export function describeState(state) {
  const lines = [];
  const { manual, pago } = state.fuentes;
  lines.push(`Manual: ${manual.activa ? `activo hasta ${formatDate(manual.venceEn)}` : 'sin concesión vigente'}`);
  lines.push(`Pagado: ${pago.activa ? `activo hasta ${formatDate(pago.venceEn)}` : 'sin suscripción vigente'}`);
  if (state.legacy?.premium && !state.activo) {
    lines.push('Tiene un valor heredado sin respaldo: no concede acceso.');
  }
  return {
    headline: state.activo ? `Premium activo hasta ${formatDate(state.venceEn)}` : 'Sin Premium activo',
    active: state.activo,
    lines,
  };
}

export function describeGrant(grant) {
  const label = { activa: 'Activa', vencida: 'Vencida', revocada: 'Revocada' }[grant.estado] ?? grant.estado;
  const when = grant.estado === 'revocada' ? formatDate(grant.revocadoEn) : `hasta ${formatDate(grant.terminaEn)}`;
  const head = grant.estado === 'revocada' ? `${label} el ${when}` : `${label} ${when}`;
  return { label, text: `${head} · ${grant.motivo}`, revocable: grant.estado === 'activa' };
}

export function friendlyError(error) {
  if (error?.code === 'REAUTHENTICATION_REQUIRED') {
    return 'Por seguridad, ingresá de nuevo (cerrá sesión y volvé a entrar) y repetí la acción.';
  }
  return error?.message || 'No se pudo completar la operación.';
}
