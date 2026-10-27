<?php
declare(strict_types=1);

require __DIR__ . '/security_regression.php';

use PawLife\Auth\AuthenticatedUser;
use PawLife\Auth\NotificationAdmin;
use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;
use PawLife\Notifications\ManualNotification;
use PawLife\Resources\CareValidator;

function rejected(callable $operation, int $status): void {
    try { $operation(); } catch (HttpException $e) {
        expect($e->status() === $status, 'status inesperado ' . $e->status());
        return;
    }
    throw new RuntimeException('La operación debía rechazarse.');
}
$store = new MemoryReminderStore();
$store->documents['configuracion/panel-notificaciones'] = ['administradores' => ['admin']];
NotificationAdmin::assertAllowed(new AuthenticatedUser('admin'), $store);
rejected(fn () => NotificationAdmin::assertAllowed(new AuthenticatedUser('normal', email: 'admin@example.test'), $store), 403);
unset($store->documents['configuracion/panel-notificaciones']);
rejected(fn () => NotificationAdmin::assertAllowed(new AuthenticatedUser('admin'), $store), 403);

$sender = new MemoryPushSender();
$manual = new ManualNotification($store, $sender);
$body = ['userId' => 'destino', 'mensaje' => '¡Su próximo cuidado ya está disponible!', 'solicitudId' => '12345678-1234-1234-1234-123456789012'];
rejected(fn () => $manual->send('admin', $body), 404);
$store->documents['users/destino'] = ['nombre' => 'Destino'];
rejected(fn () => $manual->send('admin', $body), 422);
$store->devices['destino'] = [
    ['data' => ['token' => 'demo', 'demo' => true]],
    ['data' => ['token' => 'inactivo', 'activo' => false]],
    ['data' => ['token' => 'uno']], ['data' => ['token' => 'uno']],
    ['data' => ['token' => 'dos']], ['data' => ['token' => 'tres']], ['data' => ['token' => 'cuatro']],
];
$store->devices['ajeno'] = [['data' => ['token' => 'ajeno']]];
$result = $manual->send('admin', $body);
expect($result['aceptadas'] === 3 && $result['limitados'] === 1, 'debe enviar solo a tres dispositivos distintos');
expect(array_column($sender->sent, 0) === ['uno', 'dos', 'tres'], 'no debe usar dispositivos demo, ajenos ni duplicados');
expect($manual->send('admin', $body) === $result && count($sender->sent) === 3, 'reintentar no debe duplicar envíos');
rejected(fn () => $manual->send('admin', array_replace($body, ['mensaje' => 'Otro'])), 409);
rejected(fn () => ManualNotification::validate(array_replace($body, ['userId' => '../users/ajeno'])), 400);
rejected(fn () => ManualNotification::validate(array_replace($body, ['mensaje' => str_repeat('a', 241)])), 400);
rejected(fn () => ManualNotification::validate(array_replace($body, ['mensaje' => '  '])), 400);
$store->documents['users/destino']['preferencias'] = ['avisosEquipo' => false];
rejected(fn () => $manual->send('admin', array_replace($body, ['solicitudId' => '12345678-1234-1234-1234-123456789013'])), 422);
expect(count($sender->sent) === 3, 'No debe enviar después de desactivar avisos del equipo');
$muted = new MemoryReminderStore();
$muted->documents['users/dueno'] = ['preferencias' => ['recordatoriosCuidados' => false]];
$muted->reminders[] = reminder('muted', 'dueno', $date);
$muted->devices['dueno'] = [['id' => 'd', 'data' => ['token' => 'test-muted']]];
$mutedSender = new MemoryPushSender();
(new \PawLife\Notifications\PushDispatcher($muted, $mutedSender))->dispatch($now);
expect($mutedSender->sent === [], 'No debe enviar recordatorios desactivados');

$query = FirestoreClient::walkHistoryQuery('dueno', 'mascota', 9999);
expect($query['limit'] === 51, 'la consulta debe estar acotada');
expect($query['where']['compositeFilter']['filters'][0]['fieldFilter']['value']['stringValue'] === 'dueno', 'debe filtrar por la sesión');
expect($query['where']['compositeFilter']['filters'][1]['fieldFilter']['value']['stringValue'] === 'mascota', 'debe filtrar por la mascota');
expect($query['orderBy'][1]['field']['fieldPath'] === '__name__', 'debe resolver empates entre paseos');
CareValidator::check('paseos', ['fotoUrl' => 'data:image/jpeg;base64,' . base64_encode('foto')]);
rejected(fn () => CareValidator::check('paseos', ['fotoUrl' => 'https://example.test/no.jpg']), 400);
rejected(fn () => CareValidator::check('paseos', ['distanciaMetros' => -1]), 400);
echo "TODO OK: acceso administrativo, envío dirigido sin duplicados, historial y foto del paseo\n";
