<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/support/MemoryFirestoreTransport.php';

use PawLife\Auth\AuthenticatedUser;
use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;
use PawLife\Notifications\ReminderRateLimiter;
use PawLife\Privacy\AccountDeletionService;

function delCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function delReject(callable $action, int $status, ?string $code = null): void {
    try { $action(); } catch (HttpException $error) {
        delCheck($error->status() === $status, 'Estado inesperado: ' . $error->status() . ' ' . $error->getMessage());
        if ($code !== null) delCheck($error->errorCode === $code, 'Codigo inesperado: ' . var_export($error->errorCode, true));
        return;
    }
    throw new RuntimeException('Debia rechazarse con ' . $status);
}
function delFixture(): array {
    $memory = new MemoryFirestoreTransport();
    $now = new DateTimeImmutable('2026-10-20T00:00:00Z');
    $memory->put('users/a', ['nombre' => 'A', 'email' => 'a@example.test']);
    $memory->put('users/b', ['nombre' => 'B']);
    foreach (['a', 'b'] as $uid) {
        $memory->put("mascotas/pet-$uid", ['userId' => $uid, 'nombre' => "Mascota $uid", 'especie' => 'perro']);
        $memory->put("pesos/w-$uid", ['userId' => $uid, 'mascotaId' => "pet-$uid", 'fecha' => $now, 'valorKg' => 4]);
        $memory->put("vacunas/v-$uid", ['userId' => $uid, 'mascotaId' => "pet-$uid", 'nombre' => 'Rabia']);
        $memory->put("dispositivos/d-$uid", ['userId' => $uid, 'token' => "t-$uid", 'plataforma' => 'android']);
        $memory->put("exportSessions/e-$uid", ['userId' => $uid, 'estado' => 'activa']);
        $memory->put(ReminderRateLimiter::documentPath($uid), ['userId' => $uid, 'consumidos' => 1]);
    }
    $memory->put('mascotas/pet-a2', ['userId' => 'a', 'nombre' => 'Segunda', 'especie' => 'gato']);
    // Arbol antiguo: el documento padre de la mascota no existe, solo su subcoleccion.
    $memory->put('users/a/mascotas/old/pesos/p1', ['valorKg' => 3]);
    $memory->put('users/a/recordatorios/r1', ['titulo' => 'Antiguo']);
    $memory->put('configuracion/panel-notificaciones', ['administradores' => ['a', 'x']]);
    $memory->put('notificaciones/n-ajena', ['userId' => 'x', 'administradorId' => 'a', 'titulo' => 'Aviso']);
    return [$memory, new FirestoreClient('test-project', null, $memory)];
}
function delExists(MemoryFirestoreTransport $memory, string $prefix): bool {
    foreach (array_keys($memory->records) as $path) if ($path === $prefix || str_starts_with($path, $prefix . '/')) return true;
    return false;
}

$recent = new AuthenticatedUser('a', 'a@example.test', authTime: time());
$stale = new AuthenticatedUser('a', 'a@example.test', authTime: time() - 3600);

// 1. Hace falta autenticacion reciente y no se toca nada si falta.
[$memory, $db] = delFixture();
$authCalls = [];
$service = new AccountDeletionService($db, static function (string $uid) use (&$authCalls): void { $authCalls[] = $uid; });
delReject(fn () => $service->start($stale), 401, 'REAUTHENTICATION_REQUIRED');
delCheck(!isset($memory->records['accountDeletionJobs/' . PawLife\Privacy\AccountLifecycle::key('a')]), 'Se creo un job sin reautenticacion');

// 2. start bloquea la cuenta y es idempotente.
$first = $service->start($recent);
delCheck($first['estado'] === 'pendiente', 'El job debia quedar pendiente');
$again = $service->start($recent);
delCheck($again['solicitudId'] === $first['solicitudId'], 'start no es idempotente');
delCheck(count(array_filter(array_keys($memory->records), static fn ($p) => str_starts_with($p, 'accountDeletionJobs/'))) === 1, 'Hay mas de un job');
delCheck(($memory->records['users/a']['data']['estadoCuenta'] ?? null) === 'eliminando', 'El perfil no se marco como eliminando');

// 3. Con la cuenta bloqueada, ninguna escritura normal de A pasa; las de B siguen funcionando.
delReject(fn () => $db->createDocument('pesos', ['userId' => 'a', 'mascotaId' => 'pet-a', 'valorKg' => 5, 'fecha' => new DateTimeImmutable()]), 403, 'ACCOUNT_DELETING');
delReject(fn () => $db->patchDocument('mascotas/pet-a', ['nombre' => 'Nuevo']), 403, 'ACCOUNT_DELETING');
$db->createDocument('pesos', ['userId' => 'b', 'mascotaId' => 'pet-b', 'valorKg' => 6, 'fecha' => new DateTimeImmutable()]);

// 4. Reanudacion tras interrupciones: pasos acotados hasta completar; Auth se borra una sola vez.
$rounds = 0;
do {
    $status = $service->resume('a', 3);
    $rounds++;
    delCheck($rounds < 200, 'El borrado no converge');
} while ($status['estado'] !== 'completa');
delCheck($rounds > 1, 'Debia necesitar varias reanudaciones');
delCheck($authCalls === ['a'], 'Auth debia borrarse exactamente una vez: ' . json_encode($authCalls));

// 5. Todo lo de A desaparecio (raiz y arbol antiguo); lo de B quedo intacto; el rol de A se retiro.
foreach (['users/a', 'mascotas/pet-a', 'mascotas/pet-a2', 'pesos/w-a', 'vacunas/v-a', 'dispositivos/d-a', 'exportSessions/e-a'] as $path) {
    delCheck(!delExists($memory, $path), "Quedo $path");
}
delCheck(!delExists($memory, ReminderRateLimiter::documentPath('a')), 'Quedo la cuota de A');
delCheck(!delExists($memory, 'users/a/mascotas') && !delExists($memory, 'users/a/recordatorios'), 'Quedo arbol antiguo de A');
foreach (['users/b', 'mascotas/pet-b', 'pesos/w-b', 'vacunas/v-b', 'dispositivos/d-b', 'exportSessions/e-b'] as $path) {
    delCheck(delExists($memory, $path), "Se borro $path de otra cuenta");
}
delCheck(delExists($memory, ReminderRateLimiter::documentPath('b')), 'Se borro la cuota de B');
delCheck($memory->records['configuracion/panel-notificaciones']['data']['administradores'] === ['x'], 'No se retiro el rol de A');
delCheck(array_key_exists('administradorId', $memory->records['notificaciones/n-ajena']['data'])
    && $memory->records['notificaciones/n-ajena']['data']['administradorId'] === null, 'No se anonimizo el historial ajeno');
delCheck(($memory->records['notificaciones/n-ajena']['data']['userId'] ?? null) === 'x', 'Se toco un registro de otra cuenta');
$job = $memory->records['accountDeletionJobs/' . PawLife\Privacy\AccountLifecycle::key('a')]['data'];
delCheck($job['estado'] === 'completa' && ($job['userId'] ?? null) === null, 'El job no quedo completo y sin identificador');
delCheck(!str_contains(json_encode($job), 'a@example.test'), 'El job conserva datos personales');
delCheck($service->status('a')['estado'] === 'completa', 'status no refleja el final');
delCheck(count($service->resume('a')) > 0 && $authCalls === ['a'], 'Reanudar un job completo no debe repetir efectos');

// 6. Un fallo al borrar Auth deja el job pendiente y reintentable (sin perder datos ya borrados).
[$memory, $db] = delFixture();
$authCalls = []; $failAuth = true;
$service = new AccountDeletionService($db, static function (string $uid) use (&$authCalls, &$failAuth): void {
    if ($failAuth) throw new RuntimeException('Auth no disponible (fixture).');
    $authCalls[] = $uid;
});
$service->start($recent);
for ($i = 0; $i < 200; $i++) {
    try { $service->resume('a', 5); } catch (RuntimeException) { break; }
}
$jobKey = 'accountDeletionJobs/' . PawLife\Privacy\AccountLifecycle::key('a');
delCheck($memory->records[$jobKey]['data']['estado'] === 'pendiente' && $memory->records[$jobKey]['data']['fase'] === 'auth', 'El job debia esperar en la fase auth');
delCheck(!delExists($memory, 'mascotas/pet-a') && !delExists($memory, 'users/a'), 'Los datos debian estar borrados antes de Auth');
$failAuth = false;
$memory->put($jobKey, array_replace($memory->records[$jobKey]['data'], ['lease' => null, 'leaseHasta' => new DateTimeImmutable('-1 minute')]));
delCheck($service->resume('a', 5)['estado'] === 'completa' && $authCalls === ['a'], 'El reintento debia completar el borrado');

// 7. Una suscripcion o compra exige revision de soporte y no borra nada.
[$memory, $db] = delFixture();
$memory->put('suscripciones/s1', ['userId' => 'a', 'plan' => 'premium']);
$service = new AccountDeletionService($db, static function (): void {});
delReject(fn () => $service->start($recent), 409, 'ACCOUNT_BILLING_REVIEW_REQUIRED');
delCheck(delExists($memory, 'mascotas/pet-a') && ($memory->records['users/a']['data']['estadoCuenta'] ?? 'activa') === 'activa', 'Se bloqueo o borro una cuenta con suscripcion');

// 8. El worker procesa pendientes sin token del usuario y un lease vigente evita doble ejecucion.
[$memory, $db] = delFixture();
$authCalls = [];
$service = new AccountDeletionService($db, static function (string $uid) use (&$authCalls): void { $authCalls[] = $uid; });
$service->start($recent);
$jobKey = 'accountDeletionJobs/' . PawLife\Privacy\AccountLifecycle::key('a');
$memory->put($jobKey, array_replace($memory->records[$jobKey]['data'], ['lease' => 'otro', 'leaseHasta' => new DateTimeImmutable('+10 minutes')]));
$before = count($memory->records);
$service->resume('a');
delCheck(count($memory->records) === $before && $authCalls === [], 'Un lease vigente no debia dejar avanzar a otro ejecutor');
$memory->put($jobKey, array_replace($memory->records[$jobKey]['data'], ['lease' => null, 'leaseHasta' => null]));
$guard = 0; $stats = ['completadas' => 0];
while ($stats['completadas'] === 0 && $guard++ < 100) $stats = $service->runPending(2);
delCheck($authCalls === ['a'] && delExists($memory, 'users/b') && !delExists($memory, 'users/a'), 'runPending no completo el borrado de A sin afectar a B');

// 9. La firma del programador va atada a la ruta: la de recordatorios no sirve para borrar cuentas.
$options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
$cnf = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
if (getenv('OPENSSL_CONF') === false && is_file($cnf)) $options['config'] = $cnf;
$key = openssl_pkey_new($options); delCheck($key !== false, 'openssl no genero la clave');
$public = openssl_pkey_get_details($key)['key']; $stamp = (string) time();
openssl_sign("POST\n/api/internal/send-reminders\n$stamp", $raw, $key, OPENSSL_ALGO_SHA256);
$signature = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
delCheck(\PawLife\Notifications\CronSignature::valid($stamp, $signature, $public, time()), 'La firma de recordatorios debia valer en su ruta');
delCheck(!\PawLife\Notifications\CronSignature::valid($stamp, $signature, $public, time(), \PawLife\Notifications\CronSignature::DELETE_ACCOUNTS_PATH), 'La firma de recordatorios no debe valer para borrar cuentas');
openssl_sign("POST\n/api/internal/delete-accounts\n$stamp", $raw, $key, OPENSSL_ALGO_SHA256);
$signature = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
delCheck(\PawLife\Notifications\CronSignature::valid($stamp, $signature, $public, time(), \PawLife\Notifications\CronSignature::DELETE_ACCOUNTS_PATH), 'La firma de borrado debia valer en su ruta');
delCheck(!\PawLife\Notifications\CronSignature::valid($stamp, $signature, $public, time()), 'La firma de borrado no debe valer para recordatorios');

echo "TODO OK: borrado reanudable, bloqueo de escrituras, aislamiento entre cuentas, arbol antiguo, rol, Auth reintentable, suscripciones y lease\n";
