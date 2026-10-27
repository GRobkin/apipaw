<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/support/MemoryFirestoreTransport.php';

use PawLife\Auth\AuthenticatedUser;
use PawLife\Firestore\AtomicWrite;
use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;
use PawLife\Http\Request;
use PawLife\Http\Response;
use PawLife\Http\Router;
use PawLife\Notifications\DeviceRegistry;
use PawLife\Resources\Catalog;
use PawLife\Resources\ResourceController;

function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function status(callable $operation, int $expected): void {
    try { $operation(); } catch (HttpException $e) { check($e->status() === $expected, 'Estado HTTP inesperado'); return; }
    throw new RuntimeException('Se esperaba rechazo HTTP ' . $expected);
}
function fixtureRequest(string $method, string $path, array $query = [], array $body = []): Request {
    $reflection = new ReflectionClass(Request::class);
    $request = $reflection->newInstanceWithoutConstructor();
    $reflection->getConstructor()->invoke($request, $method, $path, $query, $body, []);
    return $request;
}
$memory = new MemoryFirestoreTransport();
$client = new FirestoreClient('test-project', null, $memory);
$date = new DateTimeImmutable('2026-10-20T00:00:00Z');
foreach (['a', 'b', 'c'] as $id) $memory->put('pesos/' . $id, ['userId' => 'a', 'mascotaId' => 'pet', 'fecha' => $date, 'valorKg' => 4]);
$memory->put('pesos/ajeno', ['userId' => 'b', 'mascotaId' => 'pet', 'fecha' => $date, 'valorKg' => 9]);
$seen = []; $cursor = null;
do {
    $page = $client->queryPage('pesos', ['userId' => 'a', 'mascotaId' => 'pet'], 'fecha desc', 1, $cursor);
    $seen = [...$seen, ...array_column($page['documents'], 'id')];
    $cursor = $page['nextCursor'];
} while ($cursor !== null);
check($seen === ['c', 'b', 'a'], 'Paginación con empates omitió/duplicó registros');
status(fn () => $client->queryPage('pesos', ['userId' => 'a', 'mascotaId' => 'pet'], 'fecha desc', 1, 'ajeno'), 400);
status(fn () => $client->queryPage('pesos', ['userId' => 'a', 'mascotaId' => 'otra'], 'fecha desc', 1, 'a'), 400);
status(fn () => $client->queryPage('pesos', ['userId' => 'a'], 'fecha desc', 1, '../users/a'), 400);
$missingIndex = new FirestoreClient('test-project', null, static fn (): array => [
    'status' => 400, 'body' => '{"error":{"status":"FAILED_PRECONDITION"}}', 'headers' => [],
]);
status(fn () => $missingIndex->queryPage('pesos', ['userId' => 'a'], 'fecha desc', 1), 400);

$memory->put('mascotas/pet', ['userId' => 'a', 'nombre' => 'Luna', 'especie' => 'perro']);
$controller = new ResourceController(Catalog::pesos(), $client, static fn (): string => 'pesos',
    static fn (): string => 'mascotas/pet', scope: static fn ($u): array => ['userId' => $u->uid, 'mascotaId' => 'pet']);
$page = $controller->index(fixtureRequest('GET', '/api/mascotas/pet/pesos', ['pageSize' => '1']), [], new AuthenticatedUser('a'));
check(count($page->payload['items']) === 1 && $page->payload['nextCursor'] === 'c', 'Controller no usa página real');
status(fn () => $controller->index(fixtureRequest('GET', '/', ['pageSize' => '101']), [], new AuthenticatedUser('a')), 400);
status(fn () => $controller->index(fixtureRequest('GET', '/', ['pageSize' => '1']), [], new AuthenticatedUser('b')), 404);

$memory->beforeCommit = static function ($m) use ($date): void { $m->put('pesos/a', ['userId' => 'a', 'mascotaId' => 'pet', 'fecha' => $date, 'valorKg' => 7]); };
status(fn () => $controller->update(fixtureRequest('PATCH', '/', body: ['valorKg' => 5]), ['id' => 'a'], new AuthenticatedUser('a')), 409);
check($memory->records['pesos/a']['data']['valorKg'] === 7, 'Una escritura obsoleta sobreescribió la concurrente');

$router = new Router();
$called = false;
$router->get('/api/mascotas/{id}', static function () use (&$called): Response { $called = true; return Response::ok([]); });
status(fn () => $router->dispatch(fixtureRequest('GET', '/api/mascotas/a%2F..%2Fusers')), 400);
check(!$called, 'Identificador con barras atravesó el límite HTTP');

$memory->put('users/a', ['nombre' => 'Cuenta A']);
$registry = new DeviceRegistry($client);
$token = 'token-fixture:sin-valor-real';
$memory->put('dispositivos/' . $token, ['token' => $token, 'userId' => 'a', 'plataforma' => 'android']);
$memory->loseCommitResponse = true;
try { $registry->register('a', ['token' => $token, 'plataforma' => 'android']); } catch (RuntimeException) {}
$saved = $registry->register('a', ['token' => $token, 'plataforma' => 'android', 'userId' => 'b', 'premium' => true]);
$path = 'dispositivos/' . DeviceRegistry::idFor($token);
check(isset($memory->records[$path]) && !isset($memory->records['dispositivos/' . $token]), 'La migración/reintento dejó el token como ID legacy');
check($saved['data']['userId'] === 'a' && !isset($saved['data']['premium']), 'Se aceptó identidad o privilegios del cliente');
check($saved['id'] !== $token && str_starts_with($saved['id'], 'device-'), 'Token expuesto como ID');
check($registry->find('a', $token)['id'] === $saved['id'], 'Compatibilidad con ID legacy perdida');
$memory->put('users/b', ['nombre' => 'Cuenta B']);
status(fn () => $registry->register('b', ['token' => $token, 'plataforma' => 'ios']), 409);
status(fn () => $registry->find('b', $saved['id']), 404);
status(fn () => $registry->remove('b', $saved['id']), 404);
check($memory->records[$path]['data']['userId'] === 'a', 'Se reasignó token ajeno');

$next = 'token-fixture-concurrente';
$memory->beforeCommit = static function ($m): void { $m->put('users/a', ['estadoCuenta' => 'eliminando']); };
status(fn () => $registry->register('a', ['token' => $next, 'plataforma' => 'android']), 409);
check(!isset($memory->records['dispositivos/' . DeviceRegistry::idFor($next)]), 'Commit parcial tras cambio concurrente de cuenta');
status(fn () => $registry->register('a', ['token' => $next, 'plataforma' => 'android']), 403);
$memory->put('users/a', ['estadoCuenta' => 'activa']);
$registry->revoke('a', ['token' => $token]);
$registry->revoke('a', ['token' => $token]);
check(!isset($memory->records[$path]), 'Revocación/reintento no retiró dispositivo');

$memory->put('dispositivos/legacy-only', ['token' => 'legacy-only', 'userId' => 'a', 'plataforma' => 'ios']);
$registry->revoke('a', ['token' => 'legacy-only']);
check(!isset($memory->records['dispositivos/legacy-only']), 'No se retiró un registro antiguo sin migrar');
$built = AtomicWrite::build('test-project', [['type' => 'create', 'path' => 'system/test', 'data' => ['count' => 1]]]);
check($built['writes'][0]['currentDocument'] === ['exists' => false], 'Alta sin precondición atómica');
$before = count($memory->requests);
try { $client->commit([['type' => 'patch', 'path' => 'users/a', 'data' => ['nombre' => 'x']]]); throw new RuntimeException('Commit sin versión aceptado'); }
catch (InvalidArgumentException) {}
check(count($memory->requests) === $before, 'Se envió commit inválido a Firestore');

$quotaPath = 'operationQuotas/device-' . hash('sha256', 'a');
$window = intdiv(time(), 3600);
$memory->put($quotaPath, ['userId' => 'a', 'window' => $window, 'count' => 60]);
status(fn () => $registry->register('a', ['token' => 'quota-token', 'plataforma' => 'android']), 429);
check(!isset($memory->records['dispositivos/' . DeviceRegistry::idFor('quota-token')]), 'Cupo agotado permitió escritura');
$memory->put('dispositivos/revocable', ['userId' => 'a', 'token' => 'revocable', 'plataforma' => 'android']);
$registry->revoke('a', ['token' => 'revocable']);
check(!isset($memory->records['dispositivos/revocable']), 'El cupo bloqueó una revocación');
echo "TODO OK: páginas aisladas, versiones, migración atómica, respuesta perdida y revocación\n";
