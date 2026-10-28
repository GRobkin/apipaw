import { createBatch, sendBatch } from '/notificaciones/notification-batch.mjs';
import { createGlobalRequest, runGlobal, summarizeGlobal } from '/notificaciones/global-job.mjs';
import { grantBody, describeState, describeGrant, friendlyError } from '/notificaciones/premium-admin.mjs';

const $ = (id) => document.getElementById(id);
let auth;
let sdk;
let busy = false;
let pendingRequest = null;
let pendingGlobal = null;
let audience = 'personas';
let currentSession = 0;
let completed = false;
const recipients = new Map();

function resetRequest() {
  pendingRequest = null;
  pendingGlobal = null;
  completed = false;
  $('send-button').disabled = false;
  $('send-label').textContent = 'Revisar y enviar';
  $('new-message').hidden = true;
  $('delivery-summary').textContent = '';
  $('send-form').dataset.sceneState = 'draft';
}

function status(message, error = false, quiet = false) {
  const slot = !$('send-card').hidden ? $('admin-status-slot')
    : !$('login-view').hidden ? $('login-status-slot') : $('global-status-slot');
  slot.append($('status'));
  $('status').textContent = message;
  $('status').classList.toggle('error', error);
  $('status').classList.toggle('success', completed && !error);
  $('status').classList.toggle('sr-only', quiet);
}

function loadingButton(id, loading) {
  $(id).disabled = loading;
  $(id).setAttribute('aria-busy', String(loading));
}

$('toggle-password').addEventListener('click', () => {
  const visible = $('password').type === 'password';
  $('password').type = visible ? 'text' : 'password';
  $('toggle-password').setAttribute('aria-pressed', String(visible));
  $('toggle-password').setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
});

async function api(path, body, method = body ? 'POST' : 'GET') {
  const user = auth?.currentUser;
  if (!user) throw new Error('Ingresá nuevamente para continuar.');
  const token = await user.getIdToken();
  if (auth.currentUser !== user) throw new Error('La sesión cambió. Ingresá nuevamente.');
  const response = await fetch(path, {
    method,
    headers: { Authorization: `Bearer ${token}`, ...(body ? { 'Content-Type': 'application/json' } : {}) },
    body: body ? JSON.stringify(body) : undefined,
    cache: 'no-store',
    signal: AbortSignal.timeout(65000),
  });
  const data = await response.json();
  if (!response.ok) {
    const error = new Error(data.error?.message || 'No se pudo completar la operación.');
    error.code = data.error?.code;
    throw error;
  }
  return data;
}

function resetRecipient() {
  recipients.clear();
  updateRecipientScene();
  resetRequest();
}

function setAudience(value) {
  if (busy) return;
  audience = value;
  $('search-form').hidden = value === 'todos';
  resetRequest();
  updateRecipientScene();
}
document.querySelectorAll('input[name="audience"]').forEach((radio) => {
  radio.addEventListener('change', () => { if (radio.checked) setAudience(radio.value); });
});

function updateRecipientScene() {
  if (audience === 'todos') {
    $('preview-recipient').textContent = 'Todas las personas con avisos del equipo activados';
    $('preview-recipient').title = '';
    $('recipient-list').hidden = true;
    return;
  }
  const users = [...recipients.values()];
  const label = users.length === 1 ? users[0].label
    : users.length ? `${users.length} personas seleccionadas` : 'Seleccioná destinatarios';
  $('preview-recipient').textContent = label;
  $('preview-recipient').title = users.map((user) => user.label).join('\n');
  $('recipient-list').hidden = !users.length;
  $('recipient-list').replaceChildren(...users.map((user) => {
    const item = document.createElement('li');
    const name = document.createElement('span');
    name.textContent = user.label;
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.textContent = '×';
    remove.setAttribute('aria-label', `Quitar ${user.label}`);
    remove.disabled = busy;
    remove.addEventListener('click', () => {
      if (busy) return;
      recipients.delete(user.uid);
      resetRequest();
      updateRecipientScene();
    });
    item.append(name, remove);
    return item;
  }));
}

function updatePreview(message) {
  const previewText = message || 'Tu mensaje aparecerá aquí.';
  for (const id of ['preview-message', 'mobile-preview-message']) {
    $(id).textContent = previewText;
    $(id).classList.toggle('empty-preview', !message);
  }
}

$('message').addEventListener('input', () => {
  $('counter').textContent = `${Array.from($('message').value).length} / 240 caracteres`;
  updatePreview($('message').value);
  resetRequest();
});
$('preview-mode').addEventListener('click', () => {
  const flat = $('preview-mode').getAttribute('aria-pressed') !== 'true';
  $('preview-mode').setAttribute('aria-pressed', String(flat));
  $('message-stage').classList.toggle('is-flat', flat);
});
$('new-message').addEventListener('click', () => {
  resetRequest();
  $('message').value = '';
  $('counter').textContent = '0 / 240 caracteres';
  updatePreview('');
  status('');
  $('message').focus();
});

$('login-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  if (busy) return;
  busy = true;
  loadingButton('login-button', true);
  $('google-button').disabled = true;
  status('Verificando acceso…');
  try { await sdk.signInWithEmailAndPassword(auth, $('email').value.trim(), $('password').value); }
  catch { status('No se pudo ingresar. Revisá tus datos o usá Google si creaste tu cuenta de esa forma.', true); }
  finally { $('password').value = ''; busy = false; loadingButton('login-button', false); $('google-button').disabled = false; }
});
$('google-button').addEventListener('click', async () => {
  if (busy) return;
  busy = true;
  loadingButton('google-button', true);
  $('login-button').disabled = true;
  try { await sdk.signInWithPopup(auth, new sdk.GoogleAuthProvider()); }
  catch { status('No se pudo ingresar con Google. Permití la ventana emergente e intentá otra vez.', true); }
  finally { busy = false; loadingButton('google-button', false); $('login-button').disabled = false; }
});
$('logout-button').addEventListener('click', async () => {
  loadingButton('logout-button', true);
  try {
    await sdk.signOut(auth);
  } catch {
    status('No se pudo cerrar sesión. Reintentá.', true);
  } finally {
    loadingButton('logout-button', false);
  }
});

$('search-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  if (busy) return;
  const session = currentSession;
  busy = true;
  loadingButton('search-button', true);
  const email = $('recipient-email').value.trim();
  status('Buscando usuario…');
  try {
    const data = await api(`/api/admin/notification-users?email=${encodeURIComponent(email)}`);
    if (session !== currentSession || email !== $('recipient-email').value.trim()) return;
    if (!data.items.length) { status('No encontramos una cuenta activa con ese correo. La persona debe haber ingresado a PawLife.'); return; }
    for (const user of data.items) {
      if (recipients.size >= 20 && !recipients.has(user.uid)) {
        status('Podés seleccionar hasta 20 personas por envío.', true);
        return;
      }
      recipients.set(user.uid, { uid: user.uid, label: `${user.nombre || 'Usuario'} · ${user.email}` });
    }
    resetRequest();
    updateRecipientScene();
    $('recipient-email').value = '';
    status(`${recipients.size} destinatarios seleccionados.`, false, true);
  } catch (error) { if (session === currentSession) status(error.message, true); }
  finally {
    if (session === currentSession) {
      busy = false;
      loadingButton('search-button', false);
      updateRecipientScene();
    }
  }
});

$('send-form').addEventListener('submit', (event) => {
  event.preventDefault();
  if (busy || completed) return;
  if (audience === 'personas' && !recipients.size) { status('Añadí al menos un destinatario.', true); return; }
  const message = $('message').value.trim();
  if (!message || Array.from(message).length > 240) { status('Escribí un aviso de hasta 240 caracteres.', true); return; }
  if (audience === 'todos') {
    pendingGlobal ??= createGlobalRequest(message);
    $('confirm-recipient').textContent = 'Para TODAS las personas con avisos del equipo activados.\nSe envía en lotes desde el servidor y no se puede deshacer una vez iniciado.';
    $('confirm-message').textContent = message;
    $('confirm-dialog').showModal();
    return;
  }
  pendingRequest ??= createBatch([...recipients.values()], message);
  $('confirm-recipient').textContent = `Para ${recipients.size} persona${recipients.size === 1 ? '' : 's'}:\n${[...recipients.values()].map((user) => user.label).join('\n')}`;
  $('confirm-message').textContent = message;
  $('confirm-dialog').showModal();
});
$('cancel-send').addEventListener('click', () => $('confirm-dialog').close());
$('confirm-dialog').addEventListener('keydown', (event) => {
  if (event.key !== 'Tab') return;
  const first = $('cancel-send');
  const last = $('confirm-send');
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
});
async function confirmGlobal() {
  if (busy || !pendingGlobal) return;
  const session = currentSession;
  const request = pendingGlobal;
  busy = true;
  $('confirm-dialog').close();
  loadingButton('send-button', true);
  $('message').disabled = true;
  $('audience-mode').disabled = true;
  status('Creando el envío a todos…');
  $('delivery-summary').textContent = 'Creando el envío…';
  $('send-form').dataset.sceneState = 'sending';
  try {
    const job = await runGlobal((path, body) => api(path, body), request, {
      active: () => session === currentSession,
      onProgress: (state) => { $('delivery-summary').textContent = summarizeGlobal(state); },
    });
    if (session !== currentSession || !job) return;
    completed = job.estado === 'completado';
    const problems = job.contadores.fallidas > 0 || job.contadores.inciertas > 0 || job.estado === 'detenido';
    $('send-form').dataset.sceneState = problems ? 'warning' : 'accepted';
    $('new-message').hidden = false;
    $('new-message').focus({ preventScroll: true });
    $('send-label').textContent = completed ? 'Envío registrado' : 'Envío detenido';
    status(`FCM aceptó el aviso en ${job.contadores.aceptadas} dispositivos.\nLa recepción final depende de los permisos y la conexión de cada teléfono.`, problems);
  } catch (error) {
    if (session === currentSession) {
      status(error.message, true);
      $('send-form').dataset.sceneState = 'warning';
    }
  } finally {
    if (session === currentSession) {
      busy = false;
      loadingButton('send-button', false);
      $('send-button').disabled = completed;
      $('message').disabled = false;
      $('audience-mode').disabled = false;
      updateRecipientScene();
    }
  }
}

$('confirm-send').addEventListener('click', async () => {
  if (audience === 'todos') return confirmGlobal();
  if (busy || !pendingRequest) return;
  const session = currentSession;
  const request = pendingRequest;
  busy = true;
  $('confirm-dialog').close();
  loadingButton('send-button', true);
  $('message').disabled = true;
  $('recipient-email').disabled = true;
  updateRecipientScene();
  status('Enviando a Firebase…');
  $('delivery-summary').textContent = 'Enviando…';
  $('send-form').dataset.sceneState = 'sending';
  try {
    const results = await sendBatch(request,
      (body) => api('/api/admin/notifications', body),
      () => session === currentSession,
      (done, total) => { $('delivery-summary').textContent = `${done} / ${total} personas procesadas`; });
    if (session !== currentSession) return;
    completed = results.length === request.length && results.every((row) => !row.error);
    const accepted = results.filter((row) => row.result?.aceptadas > 0).length;
    const failed = results.some((row) => row.error || row.result?.fallidas > 0);
    $('delivery-summary').textContent = results.map((row) => `${row.label}: ${row.error || `${row.result.aceptadas}/${row.result.dispositivos} dispositivos aceptados por FCM${row.result.fallidas ? `; ${row.result.fallidas} fallidos` : ''}${row.result.limitados ? `; ${row.result.limitados} omitidos por límite` : ''}`}`).join('\n');
    $('send-form').dataset.sceneState = failed ? 'warning' : 'accepted';
    $('new-message').hidden = false;
    $('new-message').focus({ preventScroll: true });
    $('send-label').textContent = completed ? 'Envío registrado' : 'Revisar y reintentar';
    status(`FCM aceptó avisos para ${accepted} de ${request.length} personas.\nLa recepción final depende de los permisos y la conexión del teléfono.`, failed);
    // Se conserva el identificador mientras el texto y destinatario no cambien:
    // repetir tras un timeout o doble clic devuelve el mismo resultado.
  } catch (error) {
    if (session === currentSession) {
      status(error.message, true);
      $('delivery-summary').textContent = 'Sin confirmar';
      $('send-form').dataset.sceneState = 'warning';
    }
  }
  finally {
    if (session !== currentSession) return;
    busy = false;
    loadingButton('send-button', false);
    $('send-button').disabled = completed;
    $('message').disabled = false;
    $('recipient-email').disabled = false;
    updateRecipientScene();
  }
});

// ---- Premium manual -------------------------------------------------------
let premiumTarget = null;
let premiumRequestId = null;
let premiumRequestKey = '';

function resetPremium() {
  premiumTarget = null;
  premiumRequestId = null;
  premiumRequestKey = '';
  $('premium-account').hidden = true;
  $('premium-email').value = '';
  $('premium-reason').value = '';
  $('premium-days').value = '7';
  $('premium-grants').replaceChildren();
  $('premium-sources').replaceChildren();
}

function renderPremium(state) {
  const summary = describeState(state);
  $('premium-headline').textContent = summary.headline;
  $('premium-headline').classList.toggle('is-active', summary.active);
  $('premium-sources').replaceChildren(...summary.lines.map((line) => {
    const item = document.createElement('li');
    item.textContent = line;
    return item;
  }));
  const grants = state.concesiones ?? [];
  $('premium-grants').replaceChildren(...(grants.length ? grants : [null]).map((grant) => {
    const item = document.createElement('li');
    if (!grant) { item.textContent = 'Sin concesiones manuales.'; return item; }
    const info = describeGrant(grant);
    const text = document.createElement('span');
    text.textContent = info.text;
    item.append(text);
    if (info.revocable) {
      const revoke = document.createElement('button');
      revoke.type = 'button';
      revoke.textContent = 'Revocar';
      revoke.addEventListener('click', () => askPremium('Revocar concesión',
        `Se corta el acceso manual de ${premiumTarget.label}. Una suscripción paga, si la hay, no se toca.`,
        () => revokeGrant(grant.id)));
      item.append(revoke);
    }
    return item;
  }));
}

function askPremium(title, text, onConfirm) {
  $('premium-dialog-title').textContent = title;
  $('premium-dialog-text').textContent = text;
  $('premium-dialog-confirm').onclick = () => { $('premium-dialog').close(); onConfirm(); };
  $('premium-dialog').showModal();
}
$('premium-dialog-cancel').addEventListener('click', () => $('premium-dialog').close());

async function loadPremium() {
  renderPremium(await api(`/api/admin/users/${encodeURIComponent(premiumTarget.uid)}/premium`));
  $('premium-account').hidden = false;
}

$('premium-search-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  if (busy) return;
  const session = currentSession;
  busy = true;
  loadingButton('premium-search-button', true);
  status('Buscando cuenta…');
  try {
    const email = $('premium-email').value.trim();
    const data = await api(`/api/admin/premium-users?email=${encodeURIComponent(email)}`);
    if (session !== currentSession) return;
    $('premium-account').hidden = true;
    premiumRequestId = null;
    if (!data.items.length) { premiumTarget = null; status('No encontramos una cuenta con ese correo. La persona debe haber ingresado a PawLife.'); return; }
    const [user] = data.items;
    premiumTarget = { uid: user.uid, label: `${user.nombre || 'Usuario'} · ${user.email}` };
    $('premium-who').textContent = premiumTarget.label;
    await loadPremium();
    status('');
  } catch (error) { if (session === currentSession) status(friendlyError(error), true); }
  finally { if (session === currentSession) { busy = false; loadingButton('premium-search-button', false); } }
});

$('premium-grant-form').addEventListener('submit', (event) => {
  event.preventDefault();
  if (busy || !premiumTarget) return;
  const key = `${premiumTarget.uid}|${$('premium-days').value}|${$('premium-reason').value.trim()}`;
  if (key !== premiumRequestKey) { premiumRequestKey = key; premiumRequestId = crypto.randomUUID(); }
  let body;
  try { body = grantBody({ days: $('premium-days').value, motivo: $('premium-reason').value, solicitudId: premiumRequestId }); }
  catch (error) { status(error.message, true); return; }
  askPremium('Conceder Premium manual',
    `${premiumTarget.label}\nAcceso manual por ${$('premium-days').value} días.\nMotivo: ${body.motivo}`,
    () => grantPremium(body));
});

async function runPremiumAction(action, done) {
  const session = currentSession;
  busy = true;
  loadingButton('premium-grant-button', true);
  try {
    renderPremium(await action());
    if (session === currentSession) status(done);
  } catch (error) { if (session === currentSession) status(friendlyError(error), true); }
  finally { if (session === currentSession) { busy = false; loadingButton('premium-grant-button', false); } }
}
const grantPremium = (body) => runPremiumAction(
  () => api(`/api/admin/users/${encodeURIComponent(premiumTarget.uid)}/premium-grants`, body),
  'Concesión registrada. El estado de arriba lo calculó el servidor.');
const revokeGrant = (id) => runPremiumAction(
  () => api(`/api/admin/users/${encodeURIComponent(premiumTarget.uid)}/premium-grants/${encodeURIComponent(id)}`, undefined, 'DELETE'),
  'Concesión revocada.');

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
    busy = false;
    $('message').disabled = false;
    $('recipient-email').disabled = false;
    loadingButton('search-button', false);
    $('confirm-dialog').close();
    $('message').value = '';
    $('recipient-email').value = '';
    $('session-email').textContent = '';
    $('counter').textContent = '0 / 240 caracteres';
    updatePreview('');
    $('send-card').hidden = true;
    $('premium-section').hidden = true;
    resetPremium();
    $('audience-mode').disabled = false;
    document.querySelector('input[name="audience"][value="personas"]').checked = true;
    audience = 'personas';
    $('search-form').hidden = false;
    $('login-card').hidden = !user;
    $('login-view').hidden = Boolean(user);
    resetRecipient();
    if (!user) { $('login-card').hidden = false; status(''); return; }
    status('Comprobando permisos…');
    try {
      const identity = await api('/api/admin/session');
      if (session !== currentSession) return;
      $('session-email').textContent = identity.email;
      $('dispatch-studio').hidden = identity.avisos === false;
      $('premium-section').hidden = identity.premium !== true;
      $('login-card').hidden = true;
      $('send-card').hidden = false;
      status('');
      $('send-title').focus({ preventScroll: true });
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

// One driver links the artwork layers without moving account controls.
const art = document.querySelector('.login-story');
const motionPreference = matchMedia('(prefers-reduced-motion: reduce)');
let sceneFrame = 0;
let sceneX = 0;
let sceneY = 0;
let sceneTargetX = 0;
let sceneTargetY = 0;
function renderScene() {
  sceneX += (sceneTargetX - sceneX) * .09;
  sceneY += (sceneTargetY - sceneY) * .09;
  art.style.setProperty('--scene-x', `${sceneX * 16}px`);
  art.style.setProperty('--scene-y', `${sceneY * 12}px`);
  art.style.setProperty('--scene-turn', `${sceneX * 4}deg`);
  if (Math.abs(sceneTargetX - sceneX) + Math.abs(sceneTargetY - sceneY) > .002) {
    sceneFrame = requestAnimationFrame(renderScene);
  } else { sceneFrame = 0; }
}
function moveScene(x, y) {
  if (motionPreference.matches) return;
  sceneTargetX = Math.max(-1, Math.min(1, x));
  sceneTargetY = Math.max(-1, Math.min(1, y));
  if (!sceneFrame) sceneFrame = requestAnimationFrame(renderScene);
}
art.addEventListener('pointermove', (event) => {
  if (event.pointerType !== 'mouse' || tagPointer) return;
  const bounds = art.getBoundingClientRect();
  moveScene((event.clientX - bounds.left) / bounds.width - .5,
    (event.clientY - bounds.top) / bounds.height - .5);
});
art.addEventListener('pointerleave', () => moveScene(0, 0));
motionPreference.addEventListener('change', () => {
  cancelAnimationFrame(sceneFrame);
  sceneFrame = 0;
  sceneX = sceneY = sceneTargetX = sceneTargetY = 0;
  for (const property of ['--scene-x', '--scene-y', '--scene-turn']) art.style.removeProperty(property);
});
const petTag = $('pet-tag');
let tagFrame = 0;
let tagPointer;
let tagDragged = false;
let tagX = -22;
let tagY = 12;
let targetX = tagX;
let targetY = tagY;

function renderTag() {
  tagX += (targetX - tagX) * .13;
  tagY += (targetY - tagY) * .13;
  petTag.style.transform = `rotate(-17deg) rotateY(${tagX}deg) rotateX(${tagY}deg)`;
  moveScene((tagX + 22) / 70, (tagY - 12) / 40);
  if (Math.abs(targetX - tagX) + Math.abs(targetY - tagY) > .05) {
    tagFrame = requestAnimationFrame(renderTag);
  } else {
    tagFrame = 0;
  }
}
function turnTag(x, y) {
  targetX = Math.max(-55, Math.min(55, x));
  targetY = Math.max(-28, Math.min(28, y));
  if (motionPreference.matches) {
    tagX = targetX;
    tagY = targetY;
    petTag.style.transform = `rotate(-17deg) rotateY(${tagX}deg) rotateX(${tagY}deg)`;
    return;
  }
  if (!tagFrame) tagFrame = requestAnimationFrame(renderTag);
}
function releaseTag() {
  tagPointer = undefined;
  petTag.classList.remove('is-dragging');
  turnTag(-22, 12);
}
petTag.addEventListener('pointerdown', (event) => {
  if (motionPreference.matches || (event.pointerType === 'mouse' && event.button !== 0)) return;
  tagPointer = { id: event.pointerId, x: event.clientX, y: event.clientY };
  tagDragged = false;
  petTag.setPointerCapture(event.pointerId);
  petTag.classList.add('is-dragging');
});
petTag.addEventListener('pointermove', (event) => {
  if (!tagPointer || tagPointer.id !== event.pointerId) return;
  if (Math.abs(event.clientX - tagPointer.x) + Math.abs(event.clientY - tagPointer.y) > 5) tagDragged = true;
  turnTag(-22 + (event.clientX - tagPointer.x) * .35, 12 - (event.clientY - tagPointer.y) * .2);
});
petTag.addEventListener('pointerup', releaseTag);
petTag.addEventListener('pointercancel', releaseTag);
petTag.addEventListener('lostpointercapture', releaseTag);
petTag.addEventListener('blur', releaseTag);
petTag.addEventListener('click', (event) => {
  if (tagDragged && event.detail !== 0) return;
  turnTag(targetX < 0 ? 28 : -22, 12);
});
petTag.addEventListener('keydown', (event) => {
  if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Escape', 'Home'].includes(event.key)) return;
  event.preventDefault();
  if (event.key === 'Escape' || event.key === 'Home') return releaseTag();
  turnTag(targetX + (event.key === 'ArrowRight' ? 12 : event.key === 'ArrowLeft' ? -12 : 0),
    targetY + (event.key === 'ArrowUp' ? 8 : event.key === 'ArrowDown' ? -8 : 0));
});
motionPreference.addEventListener('change', () => {
  cancelAnimationFrame(tagFrame);
  tagFrame = 0;
  tagX = targetX = -22;
  tagY = targetY = 12;
  petTag.style.removeProperty('transform');
  releaseTag();
});
