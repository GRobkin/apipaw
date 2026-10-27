<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (function_exists('imagecreatefromstring') || function_exists('proc_open')) throw new RuntimeException('Ejecutar con php -n -d disable_functions=proc_open');
$jpeg = require __DIR__ . '/support/jpeg_fixture.php';
try {
    \PawLife\Resources\PhotoInput::normalize('data:image/jpeg;base64,' . base64_encode($jpeg));
    throw new RuntimeException('Aceptó foto sin decodificador');
} catch (\PawLife\Http\HttpException $error) {
    if ($error->status() !== 503 || $error->errorCode !== 'PHOTO_PROCESSOR_UNAVAILABLE') throw $error;
}
echo "TODO OK: sin decodificador no acepta imágenes silenciosamente\n";
