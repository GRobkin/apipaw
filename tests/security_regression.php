<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;
use PawLife\Http\Request;
use PawLife\Notifications\PushDispatcher;
use PawLife\Notifications\PushSender;
use PawLife\Notifications\ReminderRateLimiter;
use PawLife\Notifications\ReminderStore;

function expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class MemoryReminderStore implements ReminderStore
{
    public array $reminders = [];
    public array $devices = [];
    public array $documents = [];

    public function queryDateWindowPage(string $collection, string $field, DateTimeImmutable $start, DateTimeImmutable $end, int $limit = 100, ?array $after = null): array
    {
        $rows = array_filter($this->reminders, static function (array $row) use ($field, $start, $end, $after): bool {
            $date = $row['data'][$field];
            if (($row['data']['completado'] ?? false) === true || $date < $start || $date > $end) return false;
            if ($after === null) return true;
            return $date > $after['fecha'] || ($date == $after['fecha'] && $row['cursor']['nombre'] > $after['nombre']);
        });
        usort($rows, static fn (array $a, array $b): int =>
            ($a['data'][$field] <=> $b['data'][$field]) ?: strcmp($a['cursor']['nombre'], $b['cursor']['nombre']));
        return array_slice($rows, 0, $limit);
    }

    public function queryDocuments(string $collection, array $filters, ?string $orderBy = null, ?int $limit = null): array
    {
        return $this->devices[$filters['userId']] ?? [];
    }

    public function getDocument(string $documentPath): ?array
    {
        return $this->documents[$documentPath] ?? null;
    }

    public function getDocumentVersioned(string $documentPath): ?array
    {
        $data = $this->getDocument($documentPath);
        return $data === null ? null : ['data' => $data, 'updateTime' => 'test-version'];
    }

    public function createDocument(string $collectionPath, array $data, ?string $documentId = null): array
    {
        $path = $collectionPath . '/' . $documentId;
        if (isset($this->documents[$path])) throw new HttpException(409, 'duplicado');
        $this->documents[$path] = $data;
        return ['id' => (string) $documentId, 'data' => $data];
    }

    public function patchDocument(string $documentPath, array $data, ?string $expectedUpdateTime = null): array
    {
        expect(isset($this->documents[$documentPath]), 'PATCH sobre documento inexistente');
        $this->documents[$documentPath] = array_merge($this->documents[$documentPath], $data);
        return ['id' => basename($documentPath), 'data' => $this->documents[$documentPath]];
    }
}

final class MemoryPushSender implements PushSender
{
    public array $sent = [];

    public function send(string $deviceToken, string $reminderId, string $petId, string $type, string $message): void
    {
        $this->sent[] = [$deviceToken, $reminderId];
    }
}

function reminder(string $id, string $uid, DateTimeImmutable $date, bool $completed = false): array
{
    return [
        'id' => $id,
        'data' => ['userId' => $uid, 'fecha' => $date, 'completado' => $completed, 'mascotaId' => 'pet', 'tipo' => 'vacuna'],
        'cursor' => ['fecha' => $date, 'nombre' => 'projects/test/databases/(default)/documents/recordatorios/' . $id],
    ];
}

$now = new DateTimeImmutable('2026-10-11T10:10:00Z');
$date = new DateTimeImmutable('2026-10-11T10:00:00Z');
$query = FirestoreClient::dateWindowQuery('recordatorios', 'fecha', $date, $now, 500, reminder('r499', 'a', $date)['cursor']);
expect($query['limit'] === 500, 'la pagina debe estar acotada');
expect($query['where']['compositeFilter']['filters'][0]['fieldFilter']['field']['fieldPath'] === 'completado', 'los avisos completados deben filtrarse en Firestore');
expect($query['orderBy'][1]['field']['fieldPath'] === '__name__', 'el orden debe deshacer empates');
expect($query['startAt']['before'] === false, 'el cursor debe empezar despues del ultimo documento');
expect($query['startAt']['values'][1]['referenceValue'] === reminder('r499', 'a', $date)['cursor']['nombre'], 'el cursor debe usar la ruta completa');

$db = new MemoryReminderStore();
$sender = new MemoryPushSender();
for ($i = 0; $i < 13000; $i++) {
    $db->reminders[] = reminder(sprintf('a%05d', $i), 'atacante', $date, true);
}
for ($i = 0; $i < 500; $i++) {
    $db->reminders[] = reminder(sprintf('r%03d', $i), 'atacante', $date);
}
$db->reminders[] = reminder('zvictima', 'victima', $date);
$db->devices['victima'] = [['id' => 'device', 'data' => ['token' => 'victim-token', 'activo' => true]]];
$dispatcher = new PushDispatcher($db, $sender);
expect($dispatcher->dispatch($now)['recordatorios'] === 500, 'el primer ciclo debe avanzar sin 503');
expect($sender->sent === [], 'el primer ciclo no debe enviar los avisos de la otra cuenta aun');
expect($db->documents['system/push-dispatch']['nombre'] === reminder('r499', 'atacante', $date)['cursor']['nombre'], 'debe guardar la posicion');
expect($dispatcher->dispatch($now)['enviados'] === 1, 'el segundo ciclo debe alcanzar a la otra cuenta');
expect($sender->sent === [['victim-token', 'zvictima']], 'el aviso de la otra cuenta debe salir');

$db2 = new MemoryReminderStore();
$sender2 = new MemoryPushSender();
$db2->reminders[] = reminder('uno', 'dueno', $date);
for ($i = 0; $i < 11; $i++) {
    $db2->devices['dueno'][] = ['id' => 'd' . $i, 'data' => ['token' => 'token-' . $i, 'activo' => true]];
}
$dispatcher2 = new PushDispatcher($db2, $sender2);
expect($dispatcher2->dispatch($now)['enviados'] === 10, 'el presupuesto de envios debe seguir siendo diez');
expect($dispatcher2->dispatch($now)['enviados'] === 1, 'el dispositivo restante debe salir en el ciclo siguiente');
expect(count($sender2->sent) === 11, 'ningun dispositivo debe recibir un duplicado');

$quotaStore = new MemoryReminderStore();
$limiter = new ReminderRateLimiter($quotaStore);
$quotaClock = new DateTimeImmutable('2026-10-11T10:00:00Z');
for ($i = 0; $i < 100; $i++) $limiter->consume('atacante', $quotaClock);
try {
    $limiter->consume('atacante', $quotaClock);
    throw new RuntimeException('acepto mas de cien cambios en dos horas');
} catch (HttpException $e) {
    expect($e->status() === 429, 'el limite por cuenta debe producir 429');
}
$limiter->consume('otra-cuenta', $quotaClock);
$limiter->consume('atacante', $quotaClock->modify('+2 hours'));

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/api/mascotas';
$_SERVER['CONTENT_LENGTH'] = (string) (2 * 1024 * 1024 + 1);
try {
    Request::fromGlobals();
    throw new RuntimeException('acepto un Content-Length mayor al limite');
} catch (HttpException $e) {
    expect($e->status() === 413, 'el cuerpo grande debe producir 413');
}

$readBody = new ReflectionMethod(Request::class, 'readJsonBody');
$decode = static function (string $raw, ?string $declaredLength) use ($readBody): array {
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $raw);
    rewind($stream);
    try {
        return $readBody->invoke(null, $stream, $declaredLength);
    } finally {
        fclose($stream);
    }
};

$atLimit = '{"v":"' . str_repeat('a', 2 * 1024 * 1024 - strlen('{"v":""}')) . '"}';
expect(strlen($atLimit) === 2 * 1024 * 1024, 'el control debe estar justo en el limite');
expect(strlen($decode($atLimit, null)['v']) > 400 * 1024, 'una foto o ruta grande valida debe aceptarse');
foreach ([null, '1'] as $declaredLength) {
    try {
        $decode($atLimit . ' ', $declaredLength);
        throw new RuntimeException('acepto un cuerpo mayor al limite');
    } catch (HttpException $e) {
        expect($e->status() === 413, 'el limite real debe prevalecer sobre Content-Length');
    }
}
expect($decode('{mal JSON', null) === [], 'se conserva el manejo de JSON invalido');

echo "TODO OK: seguridad de paginacion y tamano de solicitudes\n";
