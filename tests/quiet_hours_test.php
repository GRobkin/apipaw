<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/support/MemoryFirestoreTransport.php';

use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;
use PawLife\Privacy\PrivacyService;
use PawLife\Privacy\QuietHours;

function qCheck(bool $ok, string $m): void { if (!$ok) throw new RuntimeException($m); }
function qReject(callable $a): void {
    try { $a(); } catch (HttpException $e) { qCheck($e->status() === 400, 'Estado ' . $e->status()); return; }
    throw new RuntimeException('Debía rechazarse');
}
function perfil(array $q): array { return ['preferencias' => ['noMolestar' => $q]]; }
$at = static fn (string $iso): DateTimeImmutable => new DateTimeImmutable($iso);

// Por omisión apagado.
qCheck(!QuietHours::isQuiet([], $at('2026-11-15T03:00:00Z')), 'apagado por omisión');
qCheck(QuietHours::normalize(null)['habilitada'] === false, 'default');
// Franja que cruza la medianoche, en UTC.
$p = perfil(['habilitada' => true, 'desdeMin' => 1320, 'hastaMin' => 420, 'zonaHoraria' => 'UTC']);
qCheck(QuietHours::isQuiet($p, $at('2026-11-15T23:00:00Z')), '23:00 dentro');
qCheck(QuietHours::isQuiet($p, $at('2026-11-15T06:59:00Z')), '06:59 dentro');
qCheck(!QuietHours::isQuiet($p, $at('2026-11-15T07:00:00Z')), '07:00 fuera (fin exclusivo)');
qCheck(!QuietHours::isQuiet($p, $at('2026-11-15T12:00:00Z')), 'mediodía fuera');
qCheck(QuietHours::isQuiet($p, $at('2026-11-15T22:00:00Z')), '22:00 dentro (inicio inclusivo)');
// Zona horaria del usuario: 01:00 UTC es 22:00 en Montevideo (UTC-3) -> dentro; 12:00 UTC es 09:00 -> fuera.
$uy = perfil(['habilitada' => true, 'desdeMin' => 1320, 'hastaMin' => 420, 'zonaHoraria' => 'America/Montevideo']);
qCheck(QuietHours::isQuiet($uy, $at('2026-11-15T01:00:00Z')), 'Montevideo 22:00');
qCheck(!QuietHours::isQuiet($uy, $at('2026-11-15T12:00:00Z')), 'Montevideo 09:00');
qCheck(!QuietHours::isQuiet($uy, $at('2026-11-15T12:00:00Z')), 'otra vez');
// Franja en el mismo día y franja vacía.
$dia = perfil(['habilitada' => true, 'desdeMin' => 780, 'hastaMin' => 900, 'zonaHoraria' => 'UTC']);
qCheck(QuietHours::isQuiet($dia, $at('2026-11-15T14:00:00Z')) && !QuietHours::isQuiet($dia, $at('2026-11-15T15:00:00Z')), 'franja diurna');
$igual = perfil(['habilitada' => true, 'desdeMin' => 600, 'hastaMin' => 600, 'zonaHoraria' => 'UTC']);
qCheck(!QuietHours::isQuiet($igual, $at('2026-11-15T10:00:00Z')), 'sin franja si coinciden');
// Zona corrupta cae a UTC sin romper.
qCheck(QuietHours::normalize(['zonaHoraria' => 'No/Existe'])['zonaHoraria'] === 'UTC', 'zona inválida');

// Validación y guardado por la API de preferencias.
$memory = new MemoryFirestoreTransport();
$db = new FirestoreClient('test-project', null, $memory);
$memory->put('users/a', ['nombre' => 'A']);
$svc = new PrivacyService($db);
qReject(fn () => $svc->updatePreferences('a', ['noMolestar' => true]));
qReject(fn () => $svc->updatePreferences('a', ['noMolestar' => []]));
qReject(fn () => $svc->updatePreferences('a', ['noMolestar' => ['habilitada' => 'si']]));
qReject(fn () => $svc->updatePreferences('a', ['noMolestar' => ['desdeMin' => 1440]]));
qReject(fn () => $svc->updatePreferences('a', ['noMolestar' => ['desdeMin' => '600']]));
qReject(fn () => $svc->updatePreferences('a', ['noMolestar' => ['zonaHoraria' => 'Mordor/Barad']]));
qReject(fn () => $svc->updatePreferences('a', ['noMolestar' => ['extra' => 1]]));
$r = $svc->updatePreferences('a', ['noMolestar' => ['habilitada' => true, 'desdeMin' => 1320, 'hastaMin' => 420, 'zonaHoraria' => 'America/Montevideo']]);
qCheck($r['preferencias']['noMolestar'] === ['habilitada' => true, 'desdeMin' => 1320, 'hastaMin' => 420, 'zonaHoraria' => 'America/Montevideo'], 'guardado');
$r = $svc->updatePreferences('a', ['noMolestar' => ['habilitada' => false]]);
qCheck($r['preferencias']['noMolestar']['habilitada'] === false && $r['preferencias']['noMolestar']['desdeMin'] === 1320, 'parcial conserva horas');
qCheck($svc->info('a')['preferencias']['noMolestar']['zonaHoraria'] === 'America/Montevideo', 'info lo devuelve');
echo "quiet_hours_test OK\n";
