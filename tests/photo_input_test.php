<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use PawLife\Resources\PhotoInput;
use PawLife\Http\HttpException;

function checkPhoto(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejectsPhoto(string $input): void {
    try { PhotoInput::normalize($input); }
    catch (HttpException $error) { checkPhoto($error->status() === 400 && $error->errorCode === 'INVALID_PHOTO', 'Error fotográfico inconsistente'); return; }
    throw new RuntimeException('Se aceptó imagen inválida');
}
$jpeg = require __DIR__ . '/support/jpeg_fixture.php';
$uri = static fn (string $bytes): string => 'data:image/jpeg;base64,' . base64_encode($bytes);
$normalized = PhotoInput::normalize($uri($jpeg));
checkPhoto(getimagesizefromstring(base64_decode(substr($normalized, 23)))[0] === 8, 'Se perdió la imagen');
foreach (['https://example.test/photo.jpg', 'data:image/svg+xml;base64,' . base64_encode('<svg/>'),
    $uri('not a jpeg'), $uri(substr($jpeg, 0, -2)), $uri($jpeg . '<script>'),
    'data:image/jpeg;base64,***', 'data:image/jpeg;base64,' . str_repeat('a', 409601),
    'data:image/jpeg;base64,' . base64_encode($jpeg) . "\n"] as $invalid) rejectsPhoto($invalid);

// Dimensions must be checked before decompression, even for tiny payloads.
$large = $jpeg;
$sof = strpos($large, "\xFF\xC0");
$large = substr_replace($large, pack('n', 60000), $sof + 5, 2);
rejectsPhoto($uri($large));
$missingTables = $jpeg;
while (($table = strpos($missingTables, "\xFF\xDB")) !== false) {
    $segmentLength = unpack('n', substr($missingTables, $table + 2, 2))[1];
    $missingTables = substr_replace($missingTables, '', $table, $segmentLength + 2);
}
rejectsPhoto($uri($missingTables));

// Both EXIF/GPS and comments are removed, including without GD.
$private = 'Exif' . "\0\0" . 'synthetic-gps-marker';
$metadata = "\xFF\xE1" . pack('n', strlen($private) + 2) . $private;
$withMetadata = substr($jpeg, 0, 2) . $metadata . substr($jpeg, 2);
$clean = base64_decode(substr(PhotoInput::normalize($uri($withMetadata)), 23));
checkPhoto(!str_contains($clean, 'synthetic-gps-marker'), 'Se conservaron metadatos');
rejectsPhoto($uri(substr($jpeg, 0, 2) . "\xFF\xE1\xFF\xFF" . substr($jpeg, 2)));
checkPhoto(PhotoInput::prepare(['nombre' => 'Luna']) === ['nombre' => 'Luna'], 'Cambio sin foto alterado');
checkPhoto(PhotoInput::prepare(['fotoUrl' => null]) === ['fotoUrl' => null], 'No permite quitar foto');
$historical = ['fotoUrl' => 'https://example.test/historical.jpg'];
checkPhoto(PhotoInput::prepare($historical, $historical) === $historical, 'No conserva valor histórico idéntico');
try { PhotoInput::prepare(['fotoUrl' => 'https://example.test/new.jpg'], $historical); throw new RuntimeException('URL nueva aceptada'); }
catch (HttpException $error) { checkPhoto($error->status() === 400, 'URL debe ser 400'); }

// Exercise the actual CRUD boundary: reject before PATCH and preserve old URLs.
require __DIR__ . '/support/MemoryFirestoreTransport.php';
$memory = new MemoryFirestoreTransport();
$memory->put('mascotas/pet', ['userId' => 'owner', 'nombre' => 'Luna', 'especie' => 'perro'] + $historical);
$client = new \PawLife\Firestore\FirestoreClient('test-project', null, $memory);
$controller = new \PawLife\Resources\ResourceController(\PawLife\Resources\Catalog::mascotas(), $client,
    static fn (): string => 'mascotas', scope: static fn ($u): array => ['userId' => $u->uid]);
$request = static function (array $body): \PawLife\Http\Request {
    $reflection = new ReflectionClass(\PawLife\Http\Request::class);
    $request = $reflection->newInstanceWithoutConstructor();
    $reflection->getConstructor()->invoke($request, 'PATCH', '/', [], $body, []);
    return $request;
};
$owner = new \PawLife\Auth\AuthenticatedUser('owner');
try { $controller->update($request(['fotoUrl' => $uri('fake')]), ['id' => 'pet'], $owner); throw new RuntimeException('CRUD aceptó foto falsa'); }
catch (HttpException $error) { checkPhoto($error->status() === 400, 'CRUD no rechazó entrada'); }
checkPhoto(count(array_filter($memory->requests, static fn ($r): bool => $r[0] === 'PATCH')) === 0, 'Se escribió antes de validar');
$controller->update($request(['nombre' => 'Luna nueva'] + $historical), ['id' => 'pet'], $owner);
checkPhoto($memory->records['mascotas/pet']['data']['fotoUrl'] === $historical['fotoUrl'], 'Edición borró imagen histórica');
$controller->update($request(['fotoUrl' => $uri($withMetadata)]), ['id' => 'pet'], $owner);
checkPhoto(!str_contains(base64_decode(substr($memory->records['mascotas/pet']['data']['fotoUrl'], 23)), 'synthetic-gps-marker'), 'CRUD almacenó metadata privada');
$quota = new \PawLife\Security\WriteQuota($client);
for ($i = 0; $i < 30; $i++) $quota->reservePhotoAttempt('quota-user');
$called = false;
try {
    PhotoInput::prepare(['fotoUrl' => $uri($jpeg)], beforeDecode: static function () use ($quota, &$called): void {
        $quota->reservePhotoAttempt('quota-user'); $called = true;
    });
    throw new RuntimeException('Se superó la cuota');
} catch (HttpException $error) { checkPhoto($error->status() === 429 && !$called, 'No frenó antes del decoder'); }
PhotoInput::prepare(['fotoUrl' => null], beforeDecode: static function (): void { throw new RuntimeException('Quitar foto no debe consumir cuota'); });
echo 'TODO OK: fotos acotadas, JPEG, metadatos y compatibilidad; decoder=' . (PhotoInput::hasDecoder() ? 'GD' : 'Node jpeg-js') . PHP_EOL;
