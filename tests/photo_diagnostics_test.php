<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use PawLife\Resources\PhotoDiagnostics;

$r = PhotoDiagnostics::report();
foreach (['gd', 'procOpen', 'codecFile', 'jpegJs', 'nodeRoundTrip', 'fotosDisponibles'] as $key) {
    if (!is_bool($r[$key] ?? null)) throw new RuntimeException("Falta el booleano $key");
}
if ($r['fotosDisponibles'] !== ($r['gd'] || $r['nodeRoundTrip'])) throw new RuntimeException('fotosDisponibles incoherente');
if (!$r['codecFile'] || !$r['jpegJs']) throw new RuntimeException('El decodificador de respaldo no está empaquetado');
$keys = implode(',', array_keys($r));
if (preg_match('/token|secret|path|env/i', $keys)) throw new RuntimeException('El diagnóstico no debe exponer datos sensibles');
echo "TODO OK: diagnóstico de fotos (solo booleanos; GD o respaldo de Node)\n";
