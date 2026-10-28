// Informe de SOLO LECTURA sobre dos pendientes de datos en Firestore de producción:
//   1) recordatorios sin `entidadId`;
//   2) dispositivos marcados como demostración (`demo: true`).
// No escribe, no corrige y no inventa IDs. Imprime cantidades y los IDs de documento
// (no tokens, mensajes ni datos de personas) para decidir qué hacer con ellos.
//
//   FIREBASE_TOOLS_ROOT=... node tool/report-data-issues.cjs
const path = require('node:path');
const toolsRoot = process.env.FIREBASE_TOOLS_ROOT;
if (!toolsRoot) throw new Error('Indicá FIREBASE_TOOLS_ROOT (carpeta del paquete firebase-tools).');
const auth = require(path.join(toolsRoot, 'lib/auth.js'));
const scopes = require(path.join(toolsRoot, 'lib/scopes.js'));
const project = 'pawlife-84d14';
const base = `https://firestore.googleapis.com/v1/projects/${project}/databases/(default)/documents`;

async function main() {
  const account = auth.getGlobalDefaultAccount();
  if (!account) throw new Error('Iniciá sesión en Firebase CLI.');
  const token = (await auth.getAccessToken(account.tokens.refresh_token, [scopes.CLOUD_PLATFORM])).access_token;
  async function listAll(collection, mask) {
    const out = [];
    let pageToken = '';
    do {
      const params = new URLSearchParams({ pageSize: '300' });
      mask.forEach((field) => params.append('mask.fieldPaths', field));
      if (pageToken) params.set('pageToken', pageToken);
      const response = await fetch(`${base}/${collection}?${params}`, {
        headers: { Authorization: `Bearer ${token}` }, signal: AbortSignal.timeout(25000),
      });
      if (!response.ok) throw new Error(`Firestore respondió ${response.status} leyendo ${collection}.`);
      const data = await response.json();
      out.push(...(data.documents || []));
      pageToken = data.nextPageToken || '';
    } while (pageToken);
    return out;
  }
  const idOf = (doc) => doc.name.split('/').pop();
  const reminders = await listAll('recordatorios', ['tipo', 'entidadId', 'completado']);
  const withoutEntity = reminders.filter((doc) => !doc.fields?.entidadId?.stringValue);
  const devices = await listAll('dispositivos', ['demo', 'activo']);
  const demo = devices.filter((doc) => doc.fields?.demo?.booleanValue === true);
  console.log(JSON.stringify({
    project, modo: 'solo lectura',
    recordatoriosTotal: reminders.length,
    recordatoriosSinEntidadId: withoutEntity.map((doc) => ({ id: idOf(doc), tipo: doc.fields?.tipo?.stringValue ?? null, completado: doc.fields?.completado?.booleanValue ?? false })),
    dispositivosTotal: devices.length,
    dispositivosDemo: demo.map((doc) => ({ id: idOf(doc), activo: doc.fields?.activo?.booleanValue ?? null })),
    nota: 'No se corrigió nada. El entidadId solo debe completarse si se identifica con certeza la vacuna/medicamento de origen; los dispositivos demo ya se ignoran en los envíos.',
  }, null, 2));
}
main().catch((error) => { console.error(error.message); process.exitCode = 1; });
