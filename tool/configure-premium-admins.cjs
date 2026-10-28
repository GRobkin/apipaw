// Crea/actualiza configuracion/premium: quién puede gestionar Premium manual y el
// tope de duración de una concesión. Usa la sesión de Firebase CLI; no guarda
// credenciales. Por defecto NO escribe: muestra lo que haría (--apply para escribir).
//
//   FIREBASE_TOOLS_ROOT=... node tool/configure-premium-admins.cjs correo1@x.com correo2@x.com --max-days 30
//   FIREBASE_TOOLS_ROOT=... node tool/configure-premium-admins.cjs correo1@x.com --max-days 30 --apply
//
// Solo AGREGA responsables (no quita a nadie) y no toca configuracion/panel-notificaciones.
const path = require('node:path');
const toolsRoot = process.env.FIREBASE_TOOLS_ROOT;
if (!toolsRoot) throw new Error('Indicá FIREBASE_TOOLS_ROOT (carpeta del paquete firebase-tools).');
const auth = require(path.join(toolsRoot, 'lib/auth.js'));
const scopes = require(path.join(toolsRoot, 'lib/scopes.js'));
const project = 'pawlife-84d14';

const args = process.argv.slice(2);
const apply = args.includes('--apply');
const maxIndex = args.indexOf('--max-days');
const maxDays = maxIndex >= 0 ? Number(args[maxIndex + 1]) : null;
const emails = args.filter((arg, i) => !arg.startsWith('--') && i !== maxIndex + 1).map((e) => e.trim().toLowerCase());
if (!emails.length || emails.some((email) => !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email))) {
  throw new Error('Indicá los correos completos de las cuentas existentes.');
}
if (maxDays !== null && (!Number.isInteger(maxDays) || maxDays < 1 || maxDays > 365)) {
  throw new Error('--max-days debe ser un entero entre 1 y 365.');
}

async function main() {
  const account = auth.getGlobalDefaultAccount();
  if (!account) throw new Error('Iniciá sesión en Firebase CLI.');
  const token = (await auth.getAccessToken(account.tokens.refresh_token, [scopes.CLOUD_PLATFORM])).access_token;
  async function request(url, method = 'GET', body) {
    const response = await fetch(url, {
      method, headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined, signal: AbortSignal.timeout(25000),
    });
    const data = await response.json();
    if (!response.ok && response.status !== 404) throw new Error(`Firebase respondió ${response.status}: ${data.error?.status || 'error'}`);
    return response.status === 404 ? null : data;
  }
  const lookup = await request(`https://identitytoolkit.googleapis.com/v1/projects/${project}/accounts:lookup`, 'POST', { email: emails });
  const users = (lookup?.users || []).filter((user) => !user.disabled);
  const missing = emails.filter((email) => !users.map((u) => u.email.toLowerCase()).includes(email));
  const url = `https://firestore.googleapis.com/v1/projects/${project}/databases/(default)/documents/configuracion/premium`;
  const current = await request(url);
  const existing = (current?.fields?.administradores?.arrayValue?.values || []).map((v) => v.stringValue).filter(Boolean);
  const uids = [...new Set([...existing, ...users.map((user) => user.localId)])];
  const fields = { administradores: { arrayValue: { values: uids.map((uid) => ({ stringValue: uid })) } } };
  const mask = ['administradores'];
  if (maxDays !== null) { fields.duracionMaximaDias = { integerValue: String(maxDays) }; mask.push('duracionMaximaDias'); }
  if (apply && users.length) {
    await request(`${url}?${mask.map((m) => `updateMask.fieldPaths=${m}`).join('&')}`, 'PATCH', { fields });
  }
  console.log(JSON.stringify({
    project, mode: apply ? 'escrito' : 'simulación (no se escribió nada; usá --apply)',
    solicitados: emails.length, cuentasEncontradas: users.length, yaResponsables: users.filter((u) => existing.includes(u.localId)).length,
    responsablesDespues: uids.length, duracionMaximaDias: maxDays ?? current?.fields?.duracionMaximaDias?.integerValue ?? 'sin configurar (rige 30)',
    sinCuenta: missing,
  }, null, 2));
}
main().catch((error) => { console.error(error.message); process.exitCode = 1; });
