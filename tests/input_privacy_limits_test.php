<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use PawLife\Auth\AuthenticatedUser;
use PawLife\Firestore\DocumentBudget;
use PawLife\Http\ApiError;
use PawLife\Http\HttpException;
use PawLife\Http\Request;
use PawLife\Resources\Catalog;

function requireStatus(callable $operation, int $status): HttpException {
    try { $operation(); } catch (HttpException $e) {
        if ($e->status() !== $status) throw $e;
        return $e;
    }
    throw new RuntimeException('La operación debía rechazarse.');
}

$readBody = new ReflectionMethod(Request::class, 'readJsonBody');
foreach (['[]', 'null', '123', '"texto"', '{mal json', '{"a":' . str_repeat('[', 40) . '0' . str_repeat(']', 40) . '}'] as $invalid) {
    $stream = fopen('php://memory', 'w+');
    fwrite($stream, $invalid); rewind($stream);
    requireStatus(fn () => $readBody->invoke(null, $stream, null), 400);
    fclose($stream);
}
DocumentBudget::assertFits(['fotoUrl' => str_repeat('a', 409600), 'notas' => 'control de vacuna']);
requireStatus(fn () => DocumentBudget::assertFits(['a' => str_repeat('x', 500000), 'b' => str_repeat('y', 500000)]), 413);
$point = ['lat' => 0, 'lng' => 0, 'timestamp' => '2026-10-20T00:00:00Z'];
requireStatus(fn () => Catalog::paseos()->forUpdate(['ruta' => array_fill(0, DocumentBudget::MAX_ROUTE_POINTS + 1, $point)]), 400);
if (Catalog::paseos()->validateOrderBy('fechaInicio DESC') !== 'fechaInicio desc') throw new RuntimeException('Orden válido rechazado');
foreach (['perfil', 'perfilEditable', 'dispositivos', 'mascotas', 'vacunas', 'medicamentos', 'pesos', 'alimentaciones', 'paseos', 'recordatorios'] as $resource) {
    $schema = Catalog::$resource();
    $schema->validateOrderBy($schema->defaultOrderBy);
}
foreach (['userId', 'fotoUrl', 'ruta', 'fechaInicio desc, userId', '__name__', 'fechaInicio/nombre'] as $invalid) {
    requireStatus(fn () => Catalog::paseos()->validateOrderBy($invalid), 400);
}
$secret = 'contenido-privado-ejemplo';
foreach ([new RuntimeException($secret), new HttpException(502, $secret, ['token' => $secret])] as $error) {
    $response = json_encode(ApiError::response($error, 'request-de-prueba')->payload, JSON_THROW_ON_ERROR);
    $log = ApiError::logRecord($error, 'request-de-prueba');
    if (str_contains($response, $secret) || str_contains($log, $secret)) throw new RuntimeException('Contenido privado expuesto');
    if (!str_contains($response, 'request-de-prueba')) throw new RuntimeException('Falta correlación segura');
}
$reauth = requireStatus(fn () => (new AuthenticatedUser('test'))->requireRecentAuthentication(), 401);
if (ApiError::response($reauth, 'test')->payload['error']['code'] !== 'REAUTHENTICATION_REQUIRED') throw new RuntimeException('Falta código de reautenticación');
echo "TODO OK: JSON estricto, presupuestos, consultas y errores sin datos privados\n";
