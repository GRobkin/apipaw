<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/support/MemoryFirestoreTransport.php';

use PawLife\Auth\AuthenticatedUser;
use PawLife\Billing\EntitlementService;
use PawLife\Billing\ManualGrantService;
use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;
use PawLife\Privacy\AccountLifecycle;

function ok(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function rejects(callable $action, int $status, ?string $code = null): void
{
    try { $action(); } catch (HttpException $e) {
        ok($e->status() === $status, 'estado ' . $e->status() . ' en vez de ' . $status . ': ' . $e->getMessage());
        if ($code !== null) ok($e->errorCode === $code, 'código ' . var_export($e->errorCode, true));
        return;
    }
    throw new RuntimeException('Debía rechazarse');
}

$now = new DateTimeImmutable('2026-10-20T10:00:00Z');
$iso = static fn (string $modifier): string => $now->modify($modifier)->format(DATE_ATOM);
$memory = new MemoryFirestoreTransport();
$db = new FirestoreClient('test-project', null, $memory);
$svc = new ManualGrantService($db);
$ent = new EntitlementService($db);

$memory->put('configuracion/premium', ['administradores' => ['premium-admin']]);
$memory->put('configuracion/panel-notificaciones', ['administradores' => ['notif-admin', 'premium-admin']]);
$memory->put('users/ana', ['nombre' => 'Ana', 'email' => 'ana@example.test', 'premium' => false]);
$memory->put('users/beto', ['nombre' => 'Beto']);

$admin = new AuthenticatedUser('premium-admin', authTime: $now->getTimestamp() - 60);
/** El mismo administrador, con una autenticación reciente respecto de $t. */
function A(DateTimeImmutable $t): AuthenticatedUser { return new AuthenticatedUser('premium-admin', authTime: $t->getTimestamp() - 60); }
$notifOnly = new AuthenticatedUser('notif-admin', authTime: $now->getTimestamp() - 60);
$normal = new AuthenticatedUser('ana', authTime: $now->getTimestamp() - 60);
$body = ['hasta' => $iso('+10 days'), 'motivo' => 'Prueba interna del equipo', 'solicitudId' => '12345678-1234-1234-1234-123456789012'];

// ---- capacidad propia y autenticación reciente ----
rejects(fn () => $svc->grant($notifOnly, 'ana', $body, $now), 403);
rejects(fn () => $svc->grant($normal, 'ana', $body, $now), 403);
rejects(fn () => $svc->state($notifOnly, 'ana', $now), 403);
rejects(fn () => $svc->revoke($notifOnly, 'ana', 'grant-' . str_repeat('a', 64), $now), 403);
rejects(fn () => $svc->grant(new AuthenticatedUser('premium-admin', authTime: $now->getTimestamp() - 600), 'ana', $body, $now), 401, 'REAUTHENTICATION_REQUIRED');
rejects(fn () => $svc->grant(new AuthenticatedUser('premium-admin'), 'ana', $body, $now), 401, 'REAUTHENTICATION_REQUIRED');
ok(count(array_filter(array_keys($memory->records), fn ($p) => str_starts_with($p, 'premiumGrants/'))) === 0, 'nada debe escribirse sin permiso ni reautenticación');

// ---- validación ----
foreach ([
    'sin fecha' => ['hasta' => null],
    'fecha sin hora' => ['hasta' => '2026-10-25'],
    'fecha con basura' => ['hasta' => $iso('+5 days') . 'x'],
    'pasada' => ['hasta' => $iso('-1 hour')],
    'ahora mismo' => ['hasta' => $iso('+0 seconds')],
    'sobre el tope por defecto (30 días)' => ['hasta' => $iso('+31 days')],
    'sin motivo' => ['motivo' => '   '],
    'motivo largo' => ['motivo' => str_repeat('a', 141)],
    'motivo con correo' => ['motivo' => 'Cortesía para ana@example.test'],
    'motivo con documento' => ['motivo' => 'Documento 12345678'],
    'solicitud inválida' => ['solicitudId' => 'corta'],
    'campo extra' => ['premium' => true],
] as $label => $change) {
    try { $svc->grant($admin, 'ana', array_replace($body, $change), $now); }
    catch (HttpException $e) { ok($e->status() === 400, "$label: estado " . $e->status()); continue; }
    throw new RuntimeException("$label debía rechazarse");
}
rejects(fn () => $svc->grant($admin, '../users/ana', $body, $now), 400);
rejects(fn () => $svc->grant($admin, 'fantasma', $body, $now), 404);
$memory->put('users/borrando', ['estadoCuenta' => 'eliminando']);
rejects(fn () => $svc->grant($admin, 'borrando', $body, $now), 422, 'ACCOUNT_UNAVAILABLE');

// ---- conceder: efecto inmediato, auditoría y proyección ----
$g = $svc->grant($admin, 'ana', $body, $now);
ok($g['activo'] === true && $g['fuentes']['manual']['activa'] === true && $g['fuentes']['pago']['activa'] === false
    && $g['venceEn'] === $iso('+10 days') && count($g['concesiones']) === 1 && $g['concesiones'][0]['estado'] === 'activa', 'concesión mal reflejada');
$grantId = $g['concesionId'];
$audit = $memory->records['premiumAudit/audit-' . $grantId . '-conceder']['data'] ?? null;
ok($audit !== null && $audit['actorId'] === 'premium-admin' && $audit['userId'] === 'ana' && $audit['accion'] === 'conceder' && $audit['grantId'] === $grantId, 'auditoría con actor incorrecto');
ok(!array_key_exists('motivo', $audit) && !array_key_exists('email', $audit), 'la auditoría no debe copiar datos de más');
ok($memory->records['users/ana']['data']['premium'] === true && $memory->records['users/ana']['data']['premiumHasta'] instanceof DateTimeImmutable, 'proyección de compatibilidad');
ok($ent->effective('beto', $now)['activo'] === false, 'otra cuenta no se ve afectada');

// ---- doble envío ----
$again = $svc->grant(A($now->modify('+1 minute')), 'ana', $body, $now->modify('+1 minute'));
ok(($again['concesionId'] ?? $grantId) === $grantId && count($again['concesiones']) === 1, 'el reintento no debe duplicar la concesión');
ok(count(array_filter(array_keys($memory->records), fn ($p) => str_starts_with($p, 'premiumGrants/'))) === 1
    && count(array_filter(array_keys($memory->records), fn ($p) => str_starts_with($p, 'premiumAudit/'))) === 1, 'un solo registro y una sola auditoría');
rejects(fn () => $svc->grant($admin, 'ana', array_replace($body, ['motivo' => 'Otro motivo']), $now), 409);
rejects(fn () => $svc->grant($admin, 'beto', $body, $now), 409);

// ---- vencimiento verificado al consultar, sin cron ----
ok($ent->effective('ana', $now->modify('+9 days'))['activo'] === true, 'vigente antes del vencimiento');
$expired = $ent->effective('ana', $now->modify('+10 days +1 second'));
ok($expired['activo'] === false && $expired['fuentes']['manual']['activa'] === false, 'vencida: no vale aunque nada la haya limpiado');
ok($svc->state($admin, 'ana', $now->modify('+11 days'))['concesiones'][0]['estado'] === 'vencida', 'el listado la marca vencida');

// ---- valores heredados no son una compra ni una concesión ----
$memory->put('users/legado', ['premium' => true, 'premiumHasta' => $now->modify('+90 days')]);
$legacy = $ent->effective('legado', $now);
ok($legacy['activo'] === false && $legacy['legacy']['premium'] === true, 'premium heredado: se informa pero no concede acceso');
ok($memory->records['users/legado']['data']['premium'] === true, 'no se borran ni se reescriben valores heredados');

// ---- revocar ----
rejects(fn () => $svc->revoke($admin, 'ana', 'grant-xyz', $now), 404);
rejects(fn () => $svc->revoke($admin, 'ana', 'grant-' . str_repeat('b', 64), $now), 404);
rejects(fn () => $svc->revoke($admin, 'beto', $grantId, $now), 404);               // la concesión es de otra cuenta
rejects(fn () => $svc->revoke(new AuthenticatedUser('premium-admin', authTime: 1), 'ana', $grantId, $now), 401, 'REAUTHENTICATION_REQUIRED');
$revoked = $svc->revoke(A($now->modify('+1 day')), 'ana', $grantId, $now->modify('+1 day'));
ok($revoked['activo'] === false && $revoked['concesiones'][0]['estado'] === 'revocada', 'revocar corta el acceso manual');
ok(($memory->records['premiumAudit/audit-' . $grantId . '-revocar']['data']['actorId'] ?? null) === 'premium-admin', 'auditoría de revocación con actor correcto');
ok($memory->records['users/ana']['data']['premium'] === false, 'la proyección sigue al estado efectivo');
$revoked2 = $svc->revoke(A($now->modify('+2 days')), 'ana', $grantId, $now->modify('+2 days'));
ok($revoked2['activo'] === false && $memory->records['premiumGrants/' . $grantId]['data']['revocadoEn']->format(DATE_ATOM) === $iso('+1 day'), 'revocar dos veces no cambia nada');

// ---- aislamiento de fuentes: revocar lo manual no corta lo pagado ----
$memory->put('subscriptions/s1', ['userId' => 'carla', 'estado' => 'activa', 'periodoHasta' => $now->modify('+20 days'), 'creadoEn' => $now]);
$memory->put('users/carla', ['nombre' => 'Carla']);
$subBefore = $memory->records['subscriptions/s1'];
$c = $svc->grant($admin, 'carla', array_replace($body, ['solicitudId' => 'aaaaaaaa-1234-1234-1234-123456789012', 'hasta' => $iso('+5 days')]), $now);
ok($c['activo'] === true && $c['fuentes']['manual']['activa'] && $c['fuentes']['pago']['activa'] && $c['venceEn'] === $iso('+20 days'), 'unión de fuentes: manda el vencimiento mayor');
$after = $svc->revoke(A($now->modify('+1 hour')), 'carla', $c['concesionId'], $now->modify('+1 hour'));
ok($after['activo'] === true && $after['fuentes']['manual']['activa'] === false && $after['fuentes']['pago']['activa'] === true, 'revocar lo manual no corta lo pagado');
ok($memory->records['subscriptions/s1'] === $subBefore, 'la suscripción no se toca');
$memory->put('subscriptions/s1', ['estado' => 'cancelada'] + $memory->records['subscriptions/s1']['data']);
ok($ent->effective('carla', $now->modify('+2 hours'))['activo'] === false, 'sin ninguna fuente vigente queda inactivo');
$memory->put('subscriptions/s1', ['estado' => 'activa', 'periodoHasta' => $now->modify('-1 day')] + $memory->records['subscriptions/s1']['data']);
ok($ent->effective('carla', $now->modify('+2 hours'))['activo'] === false, 'una suscripción con el período vencido no vale');

// ---- dos concesiones: revocar una deja la otra ----
$memory->put('users/dario', ['nombre' => 'Dario']);
$a = $svc->grant($admin, 'dario', array_replace($body, ['solicitudId' => 'bbbbbbbb-1234-1234-1234-123456789012', 'hasta' => $iso('+3 days')]), $now);
$b = $svc->grant($admin, 'dario', array_replace($body, ['solicitudId' => 'cccccccc-1234-1234-1234-123456789012', 'hasta' => $iso('+8 days')]), $now);
$r = $svc->revoke(A($now->modify('+1 hour')), 'dario', $b['concesionId'], $now->modify('+1 hour'));
ok($r['activo'] === true && $r['venceEn'] === $iso('+3 days'), 'al revocar la mayor vale la que queda');

// ---- revocación concurrente: otra pestaña revoca justo antes del commit ----
$memory->put('users/eva', ['nombre' => 'Eva']);
$e = $svc->grant($admin, 'eva', array_replace($body, ['solicitudId' => 'dddddddd-1234-1234-1234-123456789012']), $now);
$memory->beforeCommit = static function (MemoryFirestoreTransport $m) use ($e, $now): void {
    $path = 'premiumGrants/' . $e['concesionId'];
    $m->put($path, ['revocadoEn' => $now, 'revocadoPor' => 'otra-pestana'] + $m->records[$path]['data']);
};
$concurrent = $svc->revoke(A($now->modify('+1 hour')), 'eva', $e['concesionId'], $now->modify('+1 hour'));
ok($concurrent['activo'] === false, 'una revocación concurrente resuelve sin error ni estado inconsistente');

// ---- cuenta en borrado y barrera de ciclo de vida ----
ok(in_array('premiumGrants', AccountLifecycle::OWNED, true) && in_array('premiumAudit', AccountLifecycle::OWNED, true), 'las concesiones y su auditoría se borran con la cuenta');
$memory->put('users/fede', ['nombre' => 'Fede']);
$memory->put(AccountLifecycle::path('fede'), ['estado' => 'eliminando']);
rejects(fn () => $svc->grant($admin, 'fede', array_replace($body, ['solicitudId' => 'eeeeeeee-1234-1234-1234-123456789012']), $now), 403, 'ACCOUNT_DELETING');
ok(!isset($memory->records['premiumGrants/grant-' . hash('sha256', 'premium-admin:eeeeeeee-1234-1234-1234-123456789012')]), 'no debe crearse una concesión para una cuenta que se está borrando');

// ---- tope configurable ----
$memory->put('configuracion/premium', ['administradores' => ['premium-admin'], 'duracionMaximaDias' => 60]);
$memory->put('users/gina', ['nombre' => 'Gina']);
$long = $svc->grant($admin, 'gina', array_replace($body, ['solicitudId' => 'ffffffff-1234-1234-1234-123456789012', 'hasta' => $iso('+45 days')]), $now);
ok($long['activo'] === true, 'el tope configurado permite 45 días');
rejects(fn () => $svc->grant($admin, 'gina', array_replace($body, ['solicitudId' => 'ffffffff-1234-1234-1234-123456789013', 'hasta' => $iso('+61 days')]), $now), 400);

// ---- exportación de datos propios: concesiones sin datos de quien las gestionó ----
ok(in_array('premiumGrants', PawLife\Privacy\ExportService::ROOTS, true), 'la exportación incluye las concesiones');
$projected = PawLife\Privacy\ExportService::project('premiumGrants', $grantId, $memory->records['premiumGrants/' . $grantId]['data']);
ok(isset($projected['terminaEn'], $projected['motivo']) && !array_key_exists('creadoPor', $projected) && !array_key_exists('revocadoPor', $projected)
    && !array_key_exists('userId', $projected), 'la exportación no incluye identificadores de administradores');

// ---- capacidades del panel y vista propia ----
$memory->put('configuracion/premium', ['administradores' => ['premium-admin', 'solo-premium']]);
$memory->put('configuracion/panel-notificaciones', ['administradores' => ['notif-admin', 'premium-admin']]);
ok(PawLife\Auth\PanelCapabilities::resolve($notifOnly, $db) === ['avisos' => true, 'premium' => false], 'solo avisos');
ok(PawLife\Auth\PanelCapabilities::resolve($admin, $db) === ['avisos' => true, 'premium' => true], 'ambas capacidades');
ok(PawLife\Auth\PanelCapabilities::resolve(new AuthenticatedUser('solo-premium'), $db) === ['avisos' => false, 'premium' => true], 'solo premium entra al panel');
rejects(fn () => PawLife\Auth\PanelCapabilities::resolve(new AuthenticatedUser('ana'), $db), 403);
$self = $ent->selfView('carla', $now->modify('+2 hours'));
ok(array_keys($self) === ['activo', 'estado', 'venceEn', 'fuentes'] && array_keys($self['fuentes']) === ['manual', 'pago', 'prueba'], 'la vista propia solo expone lo necesario');
ok(!str_contains(json_encode($ent->selfView('legado', $now)), 'legacy') && $ent->selfView('legado', $now)['activo'] === false, 'la vista propia no revela valores heredados ni los concede');

echo "TODO OK: premium manual (capacidad propia, reautenticación, validación, idempotencia, vencimiento al consultar, fuentes aisladas, auditoría, revocación concurrente, ciclo de vida)\n";
