<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/support/MemoryFirestoreTransport.php';

use PawLife\Auth\AuthenticatedUser;
use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;
use PawLife\Privacy\PrivacyService;
use PawLife\Privacy\ExportService;

function privacyCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function privacyReject(callable $action, int $status): void {
    try { $action(); } catch (HttpException $error) { privacyCheck($error->status() === $status, 'Estado inesperado: ' . $error->status()); return; }
    throw new RuntimeException('La operación debía rechazarse');
}
$memory = new MemoryFirestoreTransport();
$db = new FirestoreClient('test-project', null, $memory);
$memory->put('users/a', ['nombre' => 'Persona A', 'email' => 'a@example.test', 'premium' => false]);
$memory->put('users/b', ['nombre' => 'Persona B']);
$privacy = new PrivacyService($db);
privacyCheck(!$privacy->info('a')['solicitudesHabilitadas'], 'Canal ficticio habilitado');
privacyReject(fn () => $privacy->request('a', []), 503);
privacyReject(fn () => $privacy->updatePreferences('a', ['premium' => true]), 400);
privacyReject(fn () => $privacy->updatePreferences('a', ['avisosEquipo' => 'false']), 400);
$changed = $privacy->updatePreferences('a', ['avisosEquipo' => false]);
privacyCheck($changed['preferencias']['avisosEquipo'] === false && $changed['preferencias']['recordatoriosCuidados'] === true, 'Preferencias incorrectas');
$events = array_filter(array_keys($memory->records), static fn ($path): bool => str_starts_with($path, 'consentEvents/'));
$privacy->updatePreferences('a', ['avisosEquipo' => false]);
privacyCheck(count($events) === count(array_filter(array_keys($memory->records), static fn ($path): bool => str_starts_with($path, 'consentEvents/'))), 'Reintento creó evento duplicado');

$contactOnly = new PrivacyService($db, ['responsable' => 'Soporte PawLife', 'email' => 'pawlife.support@gmail.com']);
privacyCheck($contactOnly->info('a')['contacto']['email'] === 'pawlife.support@gmail.com'
    && !$contactOnly->info('a')['solicitudesHabilitadas'], 'Contacto habilitó permisos o cola sin atención');
privacyReject(fn () => $contactOnly->request('a', []), 503);
$configured = new PrivacyService($db, ['responsable' => 'Fixture de pruebas', 'email' => 'privacy@example.test', 'recepcionHabilitada' => true]);
$body = ['tipo' => 'acceso', 'detalle' => 'Solicito información complementaria.', 'solicitudId' => '0123456789abcdef'];
$request = $configured->request('a', $body);
privacyCheck($configured->request('a', $body)['id'] === $request['id'], 'Solicitud duplicada');
privacyReject(fn () => $configured->request('a', array_replace($body, ['detalle' => 'Otro contenido'])), 409);
privacyReject(fn () => $configured->requestStatus('b', $request['id']), 404);
privacyCheck(count($configured->requests('a', null)['items']) === 1, 'No lista solicitudes propias');

foreach (['p1', 'p2', 'p3'] as $id) $memory->put('mascotas/' . $id, ['userId' => 'a', 'nombre' => $id, 'especie' => 'perro']);
$memory->put('mascotas/foreign', ['userId' => 'b', 'nombre' => 'PRIVATE_B', 'especie' => 'perro']);
$memory->put('dispositivos/PRIVATE_FCM_TOKEN', ['userId' => 'a', 'token' => 'PRIVATE_FCM_TOKEN', 'plataforma' => 'android']);
$memory->put('notificaciones/n1', ['userId' => 'a', 'mensaje' => 'Aviso propio', 'administradorId' => 'PRIVATE_ADMIN', 'tokenHash' => 'PRIVATE_DEVICE_HASH']);
$memory->put('users/a/mascotas/legacy', ['nombre' => 'Mascota antigua', 'especie' => 'gato']);
$memory->put('users/a/mascotas/missing/pesos/w', ['fecha' => new DateTimeImmutable(), 'valorKg' => 4]);
$userA = new AuthenticatedUser('a', email: 'a@example.test', authTime: time());
$userB = new AuthenticatedUser('b', authTime: time());
$exports = new ExportService($db);
privacyReject(fn () => $exports->start(new AuthenticatedUser('a', authTime: time() - 1000)), 401);
$session = $exports->start($userA);
privacyReject(fn () => $exports->page($userB, $session['id'], $session['cursor']), 404);
privacyReject(fn () => $exports->page($userA, $session['id'], str_repeat('a', 32)), 400);
$first = $exports->page($userA, $session['id'], $session['cursor']);
privacyCheck($exports->page($userA, $session['id'], $session['cursor']) === $first, 'Reintento de página cambió el cursor');
$rows = $first['items']; $cursor = $first['nextCursor']; $pages = 1;
while ($cursor !== null && $pages++ < 100) {
    $page = $exports->page($userA, $session['id'], $cursor);
    $rows = [...$rows, ...$page['items']]; $cursor = $page['nextCursor'];
}
privacyCheck($cursor === null && $pages > 10, 'No recorrió todas las categorías/páginas');
$json = json_encode($rows, JSON_THROW_ON_ERROR);
foreach (['PRIVATE_B', 'PRIVATE_ADMIN', 'PRIVATE_FCM_TOKEN', 'PRIVATE_DEVICE_HASH'] as $secret) privacyCheck(!str_contains($json, $secret), 'Exportación filtró datos privados');
privacyCheck(str_contains($json, 'Mascota antigua') && str_contains($json, 'valorKg'), 'Omitió legacy/padre ausente');
$petRows = array_values(array_filter($rows, static fn ($row): bool => $row['categoria'] === 'mascotas'));
privacyCheck(count($petRows) === 4, 'Exportación incompleta de mascotas');
$sessionDoc = $memory->records['exportSessions/' . $session['id']]['data'];
privacyCheck(!str_contains(json_encode($sessionDoc), 'Mascota antigua'), 'Sesión almacenó payload privado');
$cancelled = $exports->start($userA); $exports->cancel($userA, $cancelled['id']);
privacyReject(fn () => $exports->page($userA, $cancelled['id'], $cancelled['cursor']), 410);
$expired = $exports->start($userA);
$record = $memory->records['exportSessions/' . $expired['id']]['data'];
$memory->put('exportSessions/' . $expired['id'], array_replace($record, ['venceEn' => new DateTimeImmutable('-1 minute')]));
privacyReject(fn () => $exports->page($userA, $expired['id'], $expired['cursor']), 410);
$memory->put('users/a', ['estadoCuenta' => 'eliminando']);
privacyReject(fn () => $exports->page($userA, $session['id'], $sessionDoc['previousCursor']), 403);
foreach (['cancelacion', 'borrado'] as $race) {
    $raceMemory = new MemoryFirestoreTransport();
    $raceMemory->put('users/a', ['nombre' => 'A']);
    $raceMemory->put('mascotas/p', ['userId' => 'a', 'nombre' => 'Luna', 'especie' => 'perro']);
    $raceExport = new ExportService(new FirestoreClient('test-project', null, $raceMemory));
    $raceSession = $raceExport->start($userA);
    $profilePage = $raceExport->page($userA, $raceSession['id'], $raceSession['cursor']);
    $petCursor = $profilePage['nextCursor'];
    $raceExport->page($userA, $raceSession['id'], $petCursor);
    $raceMemory->beforeQuery = static function ($m) use ($race, $raceSession): void {
        if ($race === 'borrado') $m->put('users/a', ['estadoCuenta' => 'eliminando']);
        else {
            $path = 'exportSessions/' . $raceSession['id'];
            $m->put($path, array_replace($m->records[$path]['data'], ['estado' => 'cancelada']));
        }
    };
    privacyReject(fn () => $raceExport->page($userA, $raceSession['id'], $petCursor), $race === 'borrado' ? 403 : 409);
}
echo "TODO OK: preferencias, solicitudes idempotentes, exportación completa propia/legacy sin secretos, expiración y cancelación\n";
