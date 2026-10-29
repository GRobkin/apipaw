<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use PawLife\Http\HttpException;
use PawLife\Resources\CareValidator;
use PawLife\Resources\Catalog;

$schema = Catalog::visitas();
$ok = $schema->forCreate(['fecha' => '2026-10-01T10:00:00Z', 'motivo' => 'Control anual', 'veterinario' => 'Dra. Pérez']);
if (($ok['motivo'] ?? null) !== 'Control anual') { fwrite(STDERR, "visita no se creó\n"); exit(1); }

try { $schema->forCreate(['fecha' => '2026-10-01T10:00:00Z']); fwrite(STDERR, "motivo obligatorio\n"); exit(1); }
catch (HttpException) {}

$data = ['fecha' => new DateTimeImmutable('2026-10-10'), 'proximaFecha' => new DateTimeImmutable('2026-10-01')];
try { CareValidator::check('visitas', $data); fwrite(STDERR, "proximaFecha anterior aceptada\n"); exit(1); }
catch (HttpException) {}
CareValidator::check('visitas', ['fecha' => new DateTimeImmutable('2026-10-10'), 'proximaFecha' => new DateTimeImmutable('2027-10-10')]);
echo "visits_test OK\n";
