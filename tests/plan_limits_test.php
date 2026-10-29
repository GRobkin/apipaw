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

// ---- compra de prueba: levanta el tope de verdad y se puede cancelar ----
use PawLife\Billing\TestPurchaseService;
$memory2 = new MemoryFirestoreTransport();
$db2 = new FirestoreClient('test-project', null, $memory2);
$memory2->put('mascotas/a', ['userId' => 'caro', 'nombre' => 'A']);
$memory2->put('mascotas/b', ['userId' => 'caro', 'nombre' => 'B']);
try { PlanLimits::assertCanAddPet($db2, 'caro'); throw new RuntimeException('debía rechazar'); }
catch (HttpException $e) { ok($e->status() === 402, 'tope sin Premium'); }
$svc = new TestPurchaseService($db2);
try { $svc->buy('caro', 'trimestral'); throw new RuntimeException('plan inválido'); }
catch (HttpException $e) { ok($e->status() === 400, 'plan inválido -> 400'); }
$vista = $svc->buy('caro', 'mensual');
ok($vista['activo'] === true, 'tras comprar, Premium activo');
PlanLimits::assertCanAddPet($db2, 'caro');
$vista = $svc->buy('caro', 'anual'); // recomprar no duplica ni falla
ok($vista['activo'] === true, 'recompra');
ok(count(array_filter(array_keys($memory2->records), fn ($p) => str_starts_with($p, 'premiumGrants/'))) === 1, 'un solo registro por cuenta');
$svc->cancel('caro');
try { PlanLimits::assertCanAddPet($db2, 'caro'); throw new RuntimeException('debía volver el tope'); }
catch (HttpException $e) { ok($e->status() === 402, 'al cancelar vuelve el tope'); }
// No afecta a otras cuentas
$memory2->put('mascotas/c', ['userId' => 'otra', 'nombre' => 'C']);
$memory2->put('mascotas/d', ['userId' => 'otra', 'nombre' => 'D']);
$svc->buy('caro', 'mensual');
try { PlanLimits::assertCanAddPet($db2, 'otra'); throw new RuntimeException('otra cuenta no debe heredar Premium'); }
catch (HttpException $e) { ok($e->status() === 402, 'aislamiento entre cuentas'); }
// Interruptor de apagado
putenv('PREMIUM_TEST_PURCHASES=0'); $_ENV['PREMIUM_TEST_PURCHASES'] = '0';
try { $svc->buy('otra', 'mensual'); throw new RuntimeException('debía estar apagado'); }
catch (HttpException $e) { ok($e->status() === 403 && $e->errorCode === 'TEST_PURCHASES_DISABLED', 'apagado -> 403'); }
echo "test_purchase OK\n";
