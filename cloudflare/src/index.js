const REMINDERS_PATH = '/api/internal/send-reminders';
const DELETE_ACCOUNTS_PATH = '/api/internal/delete-accounts';
const INACTIVITY_PATH = '/api/internal/inactivity-reminders';
const NOTIFICATION_JOBS_PATH = '/api/internal/process-notification-jobs';

async function importKey(pem) {
  const keyBytes = Uint8Array.from(
    atob(pem.replace(/-----[^-]+-----/g, '').replace(/\s/g, '')),
    (char) => char.charCodeAt(0),
  );
  return crypto.subtle.importKey(
    'pkcs8', keyBytes, { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' }, false, ['sign'],
  );
}

// Cada firma cubre metodo, ruta y hora: una firma de una ruta no sirve en otra.
async function call(key, baseUrl, path) {
  const timestamp = Math.floor(Date.now() / 1000).toString();
  const payload = `POST\n${path}\n${timestamp}`;
  const signed = await crypto.subtle.sign('RSASSA-PKCS1-v1_5', key, new TextEncoder().encode(payload));
  const signature = btoa(String.fromCharCode(...new Uint8Array(signed)))
    .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const response = await fetch(new URL(path, baseUrl), {
    method: 'POST',
    headers: { 'x-pawlife-timestamp': timestamp, 'x-pawlife-signature': signature },
  });
  if (!response.ok) {
    throw new Error(`La API de PawLife respondio ${response.status} en ${path}.`);
  }
  console.log(path, JSON.stringify(await response.json()));
}

export default {
  async fetch() {
    return new Response('Not found', { status: 404 });
  },

  async scheduled(_event, env, ctx) {
    ctx.waitUntil((async () => {
      if (!env.PUSHPOLL_PRIVATE_KEY) {
        throw new Error('Falta PUSHPOLL_PRIVATE_KEY en Cloudflare.');
      }
      const key = await importKey(env.PUSHPOLL_PRIVATE_KEY);
      const baseUrl = new URL(env.API_URL).origin;
      // Un fallo en una tarea no debe impedir la otra.
      const results = await Promise.allSettled([
        call(key, baseUrl, REMINDERS_PATH),
        call(key, baseUrl, DELETE_ACCOUNTS_PATH),
        // El aviso generado aquí lo envía la siguiente ejecución de REMINDERS_PATH.
        call(key, baseUrl, INACTIVITY_PATH),
        // Envío a todos en curso (no hace nada si no hay).
        call(key, baseUrl, NOTIFICATION_JOBS_PATH),
      ]);
      const failed = results.filter((result) => result.status === 'rejected');
      if (failed.length > 0) {
        throw new Error(failed.map((result) => result.reason.message).join(' | '));
      }
    })());
  },
};
