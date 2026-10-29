<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/support/MemoryFirestoreTransport.php';

use PawLife\Billing\PlanLimits;
use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;

function ok(bool $c, string $m): void { if (!$c) throw new RuntimeException($m); }

$memory = new MemoryFirestoreTransport();
$db = new FirestoreClient('test-project', null, $memory);
$future = (new DateTimeImmutable('+10 days'))->format(DATE_ATOM);

// Con 0 y 1 mascotas se puede agregar otra.
PlanLimits::assertCanAddPet($db, 'ana');
$memory->put('mascotas/m1', ['userId' => 'ana', 'nombre' => 'Luna']);
PlanLimits::assertCanAddPet($db, 'ana');

// Con 2 mascotas, el plan gratuito rechaza la tercera con 402.
$memory->put('mascotas/m2', ['userId' => 'ana', 'nombre' => 'Rex']);
try {
    PlanLimits::assertCanAddPet($db, 'ana');
    throw new RuntimeException('Debía rechazarse la tercera mascota');
} catch (HttpException $e) {
    ok($e->status() === 402 && $e->errorCode === 'PLAN_PET_LIMIT', 'esperaba 402 PLAN_PET_LIMIT');
}

// Las mascotas de otra cuenta no cuentan.
PlanLimits::assertCanAddPet($db, 'beto');

// Con Premium activo no hay tope.
$memory->put('premiumGrants/g1', [
    'userId' => 'ana', 'creadoEn' => new DateTimeImmutable('-1 day'),
    'terminaEn' => new DateTimeImmutable('+10 days'), 'revocadoEn' => null,
]);
PlanLimits::assertCanAddPet($db, 'ana');
echo "plan_limits_test OK\n";
