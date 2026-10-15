const $ = (id) => document.getElementById(id);
let auth;
let sdk;
let busy = false;
let pendingRequest = null;
let currentSession = 0;
let completed = false;

function resetRequest() {
  pendingRequest = null;
  completed = false;
  $('send-button').disabled = false;
  $('send-button').textContent = 'Revisar y enviar';
  $('new-message').hidden = true;
}

function status(message, error = false) {
  $('status').textContent = message;
  $('status').classList.toggle('error', error);
}

async function api(path, body) {
  const user = auth?.currentUser;
  if (!user) throw new Error('Ingresá nuevamente para continuar.');
  const token = await user.getIdToken();
  const response = await fetch(path, {
    method: body ? 'POST' : 'GET',
    headers: { Authorization: `Bearer ${token}`, ...(body ? { 'Content-Type': 'application/json' } : {}) },
    body: body ? JSON.stringify(body) : undefined,
    cache: 'no-store',
    signal: AbortSignal.timeout(65000),
  });
  const data = await response.json();
  if (!response.ok) throw new Error(data.error?.message || 'No se pudo completar la operación.');
  return data;
}

function resetRecipient() {
  $('recipient').replaceChildren(new Option('Buscá un usuario por su correo', ''));
  $('recipient').disabled = true;
  resetRequest();
}

$('message').addEventListener('input', () => {
  $('counter').textContent = `${Array.from($('message').value).length} / 240 caracteres`;
  $('preview-message').textContent = $('message').value || 'Tu mensaje aparecerá aquí.';
  resetRequest();
});
$('recipient-email').addEventListener('input', resetRecipient);
$('recipient').addEventListener('change', resetRequest);
$('new-message').addEventListener('click', () => {
  resetRequest();
  $('message').value = '';
  $('counter').textContent = '0 / 240 caracteres';
  $('preview-message').textContent = 'Tu mensaje aparecerá aquí.';
  status('Escribí el nuevo aviso.');
  $('message').focus();
});

$('login-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  if (busy) return;
  busy = true;
  $('login-button').disabled = true;
  status('Verificando acceso…');
  try { await sdk.signInWithEmailAndPassword(auth, $('email').value.trim(), $('password').value); }
  catch { status('No se pudo ingresar. Revisá tus datos o usá Google si creaste tu cuenta de esa forma.', true); }
  finally { $('password').value = ''; busy = false; $('login-button').disabled = false; }
});
$('google-button').addEventListener('click', async () => {
  if (busy) return;
  busy = true;
  try { await sdk.signInWithPopup(auth, new sdk.GoogleAuthProvider()); }
  catch { status('No se pudo ingresar con Google. Permití la ventana emergente e intentá otra vez.', true); }
  finally { busy = false; }
});
$('logout-button').addEventListener('click', () => sdk.signOut(auth));

$('search-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  if (busy) return;
  const session = currentSession;
  busy = true;
  $('search-button').disabled = true;
  const email = $('recipient-email').value.trim();
  resetRecipient();
  status('Buscando usuario…');
  try {
    const data = await api(`/api/admin/notification-users?email=${encodeURIComponent(email)}`);
    if (session !== currentSession || email !== $('recipient-email').value.trim()) return;
    if (!data.items.length) { status('No encontramos una cuenta activa con ese correo. La persona debe haber ingresado a PawLife.'); return; }
    $('recipient').replaceChildren(...data.items.map((user) => new Option(`${user.nombre || 'Usuario'} · ${user.email}`, user.uid)));
    $('recipient').disabled = false;
    status('Destinatario seleccionado. Revisá el mensaje antes de enviarlo.');
  } catch (error) { if (session === currentSession) status(error.message, true); }
  finally { busy = false; $('search-button').disabled = false; }
});

$('send-form').addEventListener('submit', (event) => {
  event.preventDefault();
  if (busy || completed) return;
  if (!$('recipient').value || $('recipient').disabled) { status('Buscá y seleccioná un destinatario.', true); return; }
  const message = $('message').value.trim();
  if (!message || Array.from(message).length > 240) { status('Escribí un aviso de hasta 240 caracteres.', true); return; }
  pendingRequest ??= { solicitudId: crypto.randomUUID(), userId: $('recipient').value, mensaje: message };
  $('confirm-recipient').textContent = `Para: ${$('recipient').selectedOptions[0].textContent}`;
  $('confirm-message').textContent = message;
  $('confirm-dialog').showModal();
});
$('cancel-send').addEventListener('click', () => $('confirm-dialog').close());
$('confirm-send').addEventListener('click', async () => {
  if (busy || !pendingRequest) return;
  const session = currentSession;
  const request = { ...pendingRequest };
  busy = true;
  $('confirm-dialog').close();
  $('send-button').disabled = true;
  $('message').disabled = true;
  $('recipient-email').disabled = true;
  $('recipient').disabled = true;
  status('Enviando a Firebase…');
  try {
    const result = await api('/api/admin/notifications', request);
    if (session !== currentSession) return;
    completed = true;
    $('new-message').hidden = false;
    $('send-button').textContent = 'Envío registrado';
    status(`Firebase aceptó ${result.aceptadas} de ${result.dispositivos} notificaciones. Fallidas: ${result.fallidas}.` +
      (result.limitados ? ` Se omitieron ${result.limitados} dispositivos por el límite del envío.` : '') +
      '\nLa recepción final depende de los permisos y la conexión del teléfono.', result.fallidas > 0);
    // Se conserva el identificador mientras el texto y destinatario no cambien:
    // repetir tras un timeout o doble clic devuelve el mismo resultado.
  } catch (error) { if (session === currentSession) status(error.message, true); }
  finally {
    busy = false;
    $('send-button').disabled = completed;
    $('message').disabled = false;
    $('recipient-email').disabled = false;
    $('recipient').disabled = !$('recipient').value;
  }
});

async function initialize() {
  const version = '12.19.0';
  const [{ initializeApp }, authSdk] = await Promise.all([
    import(`https://www.gstatic.com/firebasejs/${version}/firebase-app.js`),
    import(`https://www.gstatic.com/firebasejs/${version}/firebase-auth.js`),
  ]);
  sdk = authSdk;
  const config = await fetch('/api/panel-config', { cache: 'no-store' }).then((r) => {
    if (!r.ok) throw new Error('No se pudo configurar el acceso.');
    return r.json();
  });
  auth = sdk.getAuth(initializeApp(config));
  await sdk.setPersistence(auth, sdk.inMemoryPersistence);
  sdk.onAuthStateChanged(auth, async (user) => {
    const session = ++currentSession;
    $('send-card').hidden = true;
    $('login-card').hidden = !user;
    resetRecipient();
    if (!user) { $('login-card').hidden = false; status(''); return; }
    status('Comprobando permisos…');
    try {
      const identity = await api('/api/admin/session');
      if (session !== currentSession) return;
      $('session-email').textContent = identity.email;
      $('login-card').hidden = true;
      $('send-card').hidden = false;
      status('');
    } catch (error) {
      if (session !== currentSession) return;
      await sdk.signOut(auth);
      status(error.message, true);
    }
  });
  $('login-button').disabled = false;
  $('google-button').disabled = false;
}
initialize().catch(() => status('No se pudo abrir el acceso. Comprobá tu conexión y recargá la página.', true));
