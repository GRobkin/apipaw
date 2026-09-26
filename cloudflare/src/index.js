export default {
  async fetch() {
    return new Response('Not found', { status: 404 });
  },

  async scheduled(_event, env, ctx) {
    ctx.waitUntil((async () => {
      if (!env.PUSHPOLL_PRIVATE_KEY) {
        throw new Error('Falta PUSHPOLL_PRIVATE_KEY en Cloudflare.');
      }
      const keyBytes = Uint8Array.from(
        atob(env.PUSHPOLL_PRIVATE_KEY.replace(/-----[^-]+-----/g, '').replace(/\s/g, '')),
        (char) => char.charCodeAt(0),
      );
      const key = await crypto.subtle.importKey(
        'pkcs8', keyBytes, { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' }, false, ['sign'],
      );
      const timestamp = Math.floor(Date.now() / 1000).toString();
      const payload = `POST\n/api/internal/send-reminders\n${timestamp}`;
      const signed = await crypto.subtle.sign('RSASSA-PKCS1-v1_5', key, new TextEncoder().encode(payload));
      const signature = btoa(String.fromCharCode(...new Uint8Array(signed)))
        .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
      const response = await fetch(env.API_URL, {
        method: 'POST',
        headers: { 'x-pawlife-timestamp': timestamp, 'x-pawlife-signature': signature },
      });
      if (!response.ok) {
        throw new Error(`La API de PawLife respondio ${response.status}.`);
      }
      const result = await response.json();
      console.log(JSON.stringify(result));
    })());
  },
};
