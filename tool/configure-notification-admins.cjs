// Administración local con la sesión de Firebase CLI. No guarda credenciales.
const path = require('node:path');
const toolsRoot = process.env.FIREBASE_TOOLS_ROOT;
if (!toolsRoot) throw new Error('Indicá FIREBASE_TOOLS_ROOT (carpeta del paquete firebase-tools).');
const auth = require(path.join(toolsRoot, 'lib/auth.js'));
const scopes = require(path.join(toolsRoot, 'lib/scopes.js'));
const project = 'pawlife-84d14';
const emails = process.argv.slice(2).filter((arg) => arg !== '--check').map((email) => email.trim().toLowerCase());
const check = process.argv.includes('--check');
if (!emails.length || emails.some((email) => !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email))) throw new Error('Indicá los correos completos de las cuentas existentes.');
async function main() {
  const account = auth.getGlobalDefaultAccount();
  if (!account) throw new Error('Iniciá sesión en Firebase CLI.');
  const token = (await auth.getAccessToken(account.tokens.refresh_token, [scopes.CLOUD_PLATFORM])).access_token;
  async function request(url, method = 'GET', body) {
    const response = await fetch(url, { method,
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined, signal: AbortSignal.timeout(25000) });
    const data = await response.json();
    if (!response.ok && response.status !== 404) throw new Error(`Firebase respondió ${response.status}: ${data.error?.status || 'error'}`);
    return response.status === 404 ? null : data;
  }
  const lookup = await request(`https://identitytoolkit.googleapis.com/v1/projects/${project}/accounts:lookup`, 'POST', { email: emails });
  const users = (lookup?.users || []).filter((user) => !user.disabled);
  const found = users.map((user) => user.email.toLowerCase());
  const missing = emails.filter((email) => !found.includes(email));
  const url = `https://firestore.googleapis.com/v1/projects/${project}/databases/(default)/documents/configuracion/panel-notificaciones`;
  const current = await request(url);
  const existing = (current?.fields?.administradores?.arrayValue?.values || []).map((value) => value.stringValue).filter(Boolean);
  const uids = [...new Set([...existing, ...users.map((user) => user.localId)])];
  if (!check && users.length) await request(url + '?updateMask.fieldPaths=administradores', 'PATCH', {
    fields: { administradores: { arrayValue: { values: uids.map((uid) => ({ stringValue: uid })) } } },
  });
  const configUrl = `https://identitytoolkit.googleapis.com/admin/v2/projects/${project}/config`;
  const config = await request(configUrl);
  const domains = config?.authorizedDomains || [];
  if (!check && !domains.includes('apipaw.vercel.app')) {
    await request(configUrl + '?updateMask=authorizedDomains', 'PATCH', { authorizedDomains: [...domains, 'apipaw.vercel.app'] });
  }
  console.log(JSON.stringify({ project, mode: check ? 'read-only' : 'configured', requested: emails.length,
    accountsFound: users.length, alreadyAllowed: users.filter((user) => existing.includes(user.localId)).length,
    allowedAfter: check ? existing.length : uids.length,
    panelDomainAuthorized: !check || domains.includes('apipaw.vercel.app'), missing }, null, 2));
}
main().catch((error) => { console.error(error.message); process.exitCode = 1; });
