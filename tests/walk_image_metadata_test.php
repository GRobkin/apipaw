<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use PawLife\Resources\Catalog;
use PawLife\Resources\CareValidator;
use PawLife\Http\HttpException;

$body = [
    'fechaInicio' => '2026-10-19T10:00:00Z', 'fechaFin' => '2026-10-19T10:10:00Z',
    'duracionSegundos' => 600, 'distanciaMetros' => 500, 'velocidadMaximaKmh' => 4,
    'ruta' => [],
];
$schema = Catalog::paseos();
$legacy = $schema->forCreate($body);
if ($legacy['imagenTipo'] !== 'desconocida') throw new RuntimeException('No inferir origen de imágenes legacy');
foreach (['foto', 'mapa', 'desconocida'] as $type) {
    $data = $schema->forCreate($body + ['imagenTipo' => $type]);
    CareValidator::check('paseos', $data);
    if ($schema->toJson('paseo', $data)['imagenTipo'] !== $type) throw new RuntimeException('Se perdió el origen de imagen');
}
foreach (['publica', null] as $type) {
    try {
        CareValidator::check('paseos', $schema->forCreate($body + ['imagenTipo' => $type]));
    } catch (HttpException $e) {
        if ($e->status() !== 400) throw $e;
        continue;
    }
    throw new RuntimeException('Origen inválido aceptado');
}
echo "TODO OK: origen de imagen compatible y validado\n";
