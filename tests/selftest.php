<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use PawLife\Firestore\Value;
use PawLife\Http\HttpException;
use PawLife\Resources\Catalog;
use PawLife\Support\Json;
use PawLife\Support\Jwt;

$passed = 0;
$failed = 0;

function check(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "  ok   $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL $name -> " . $e::class . ': ' . $e->getMessage() . "\n";
    }
}

function assertTrue(bool $cond, string $msg = 'no se cumplio'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($msg !== '' ? "$msg: " : '') .
            'esperaba ' . json_encode($expected) . ' y llego ' . json_encode($actual)
        );
    }
}

echo "== Autoloader ==\n";
check('carga clases del namespace PawLife', function (): void {
    assertTrue(class_exists(Catalog::class));
    assertTrue(class_exists(Value::class));
});

echo "\n== Firestore\\Value ==\n";
check('codifica los tipos basicos', function (): void {
    assertSame(['stringValue' => 'hola'], Value::encode('hola'));
    assertSame(['integerValue' => '42'], Value::encode(42));
    assertSame(['doubleValue' => 1.5], Value::encode(1.5));
    assertSame(['booleanValue' => true], Value::encode(true));
    assertSame(['nullValue' => null], Value::encode(null));
});

check('codifica fechas en RFC3339 UTC', function (): void {
    $fecha = new DateTimeImmutable('2026-09-17T12:00:00+02:00');
    $encoded = Value::encode($fecha);
    assertTrue(str_starts_with($encoded['timestampValue'], '2026-09-17T10:00:00'), 'debe pasar a UTC');
    assertTrue(str_ends_with($encoded['timestampValue'], 'Z'));
});

check('codifica listas y mapas', function (): void {
    assertSame(
        ['arrayValue' => ['values' => [['stringValue' => 'a'], ['stringValue' => 'b']]]],
        Value::encode(['a', 'b'])
    );
    assertSame(
        ['mapValue' => ['fields' => ['lat' => ['doubleValue' => 1.0]]]],
        Value::encode(['lat' => 1.0])
    );
});

check('ida y vuelta de un documento completo', function (): void {
    $original = [
        'nombre' => 'Buddy',
        'peso' => 12.4,
        'dias' => 3,
        'activo' => true,
        'horarios' => ['08:00', '20:00'],
        'punto' => ['lat' => 41.3874, 'lng' => 2.1686],
    ];
    $roundTrip = Value::decodeFields(Value::encodeFields($original));
    assertSame($original, $roundTrip);
});

check('decodifica geoPointValue de los paseos antiguos', function (): void {
    $decoded = Value::decode(['geoPointValue' => ['latitude' => 41.5, 'longitude' => 2.1]]);
    assertSame(['lat' => 41.5, 'lng' => 2.1], $decoded);
});

echo "\n== Resources\\Schema: validacion ==\n";
$paseos = Catalog::paseos();

check('acepta un paseo bien formado', function () use ($paseos): void {
    $data = $paseos->forCreate([
        'fechaInicio' => '2026-09-17T09:00:00.000Z',
        'fechaFin' => '2026-09-17T09:32:10.000Z',
        'duracionSegundos' => 1930,
        'distanciaMetros' => 2413.5,
        'velocidadMaximaKmh' => 7.8,
        'ruta' => [
            ['lat' => 41.3874, 'lng' => 2.1686, 'timestamp' => '2026-09-17T09:00:00.000Z'],
            ['lat' => 41.3880, 'lng' => 2.1690, 'timestamp' => '2026-09-17T09:00:05.000Z'],
        ],
    ]);

    assertTrue($data['fechaInicio'] instanceof DateTimeImmutable);
    assertSame(1930, $data['duracionSegundos']);
    assertSame(2413.5, $data['distanciaMetros']);
    assertSame(2, count($data['ruta']));
    assertSame(41.3874, $data['ruta'][0]['lat']);
    assertTrue($data['ruta'][0]['timestamp'] instanceof DateTimeImmutable);
});

check('rechaza un paseo al que le faltan campos', function () use ($paseos): void {
    try {
        $paseos->forCreate(['fechaInicio' => '2026-09-17T09:00:00Z']);
        throw new RuntimeException('deberia haber fallado');
    } catch (HttpException $e) {
        assertSame(400, $e->status());
        $campos = array_keys($e->details());
        sort($campos);
        assertSame(
            ['distanciaMetros', 'duracionSegundos', 'fechaFin', 'ruta', 'velocidadMaximaKmh'],
            $campos
        );
    }
});

check('rechaza coordenadas fuera de rango', function () use ($paseos): void {
    try {
        $paseos->forCreate([
            'fechaInicio' => '2026-09-17T09:00:00Z',
            'fechaFin' => '2026-09-17T09:30:00Z',
            'duracionSegundos' => 1800,
            'distanciaMetros' => 100.0,
            'velocidadMaximaKmh' => 5.0,
            'ruta' => [['lat' => 200, 'lng' => 2.0]],
        ]);
        throw new RuntimeException('deberia haber fallado');
    } catch (HttpException $e) {
        assertTrue(str_contains($e->details()['ruta'], 'fuera del rango'), $e->details()['ruta']);
    }
});

check('rechaza tipos equivocados con el nombre del campo', function () use ($paseos): void {
    try {
        $paseos->forCreate([
            'fechaInicio' => '2026-09-17T09:00:00Z',
            'fechaFin' => '2026-09-17T09:30:00Z',
            'duracionSegundos' => 'mucho',
            'distanciaMetros' => 'lejos',
            'velocidadMaximaKmh' => 5.0,
            'ruta' => [],
        ]);
        throw new RuntimeException('deberia haber fallado');
    } catch (HttpException $e) {
        assertSame('Debe ser un numero entero.', $e->details()['duracionSegundos']);
        assertSame('Debe ser un numero.', $e->details()['distanciaMetros']);
    }
});

check('aplica valores por defecto', function (): void {
    $vacunas = Catalog::vacunas();
    $data = $vacunas->forCreate([
        'nombre' => 'Rabia',
        'fechaAplicacion' => '2026-01-10T00:00:00Z',
        'proximaFecha' => '2027-01-10T00:00:00Z',
    ]);
    assertSame(3, $data['anticipacionDias']);

    $medicamentos = Catalog::medicamentos();
    $med = $medicamentos->forCreate([
        'nombre' => 'Apoquel',
        'dosis' => '16mg',
        'horarios' => ['08:00'],
        'fechaInicio' => '2026-09-01T00:00:00Z',
    ]);
    assertSame(true, $med['activo']);
    assertSame(null, $med['fechaFin']);
});

check('PATCH solo toca los campos enviados', function (): void {
    $mascotas = Catalog::mascotas();
    $data = $mascotas->forUpdate(['notas' => 'Alergica al pollo']);
    assertSame(['notas' => 'Alergica al pollo'], $data);
});

check('PATCH vacio da error en vez de escribir nada', function (): void {
    try {
        Catalog::mascotas()->forUpdate([]);
        throw new RuntimeException('deberia haber fallado');
    } catch (HttpException $e) {
        assertSame(400, $e->status());
        assertTrue(str_contains($e->getMessage(), 'ningun campo'));
    }
});

check('PATCH no deja vaciar un campo obligatorio', function (): void {
    try {
        Catalog::mascotas()->forUpdate(['nombre' => null]);
        throw new RuntimeException('deberia haber fallado');
    } catch (HttpException $e) {
        assertSame('No puede quedar vacio.', $e->details()['nombre']);
    }
});

echo "\n== Resources\\Schema: salida JSON ==\n";
check('devuelve fechas ISO y la ruta con sus tiempos', function () use ($paseos): void {
    $data = $paseos->forCreate([
        'fechaInicio' => '2026-09-17T09:00:00.000Z',
        'fechaFin' => '2026-09-17T09:32:10.000Z',
        'duracionSegundos' => 1930,
        'distanciaMetros' => 2413.5,
        'velocidadMaximaKmh' => 7.8,
        'ruta' => [['lat' => 41.3874, 'lng' => 2.1686, 'timestamp' => '2026-09-17T09:00:00.000Z']],
    ]);

    // Simula el viaje completo por Firestore.
    $stored = Value::decodeFields(Value::encodeFields($data));
    $json = $paseos->toJson('paseo_1', $stored);

    assertSame('paseo_1', $json['id']);
    assertSame('2026-09-17T09:00:00.000Z', $json['fechaInicio']);
    assertSame(1930, $json['duracionSegundos']);
    assertSame(2413.5, $json['distanciaMetros']);
    assertSame(41.3874, $json['ruta'][0]['lat']);
    assertSame('2026-09-17T09:00:00.000Z', $json['ruta'][0]['timestamp']);

    // Y que todo eso sea serializable sin sorpresas.
    assertTrue(str_contains(Json::encode($json), '"id":"paseo_1"'));
});

check('un paseo antiguo con GeoPoint se sigue leyendo', function () use ($paseos): void {
    // Como lo escribia la app cuando hablaba con Firestore: ruta de GeoPoint.
    $stored = Value::decodeFields([
        'fechaInicio' => ['timestampValue' => '2025-04-01T08:00:00.000Z'],
        'fechaFin' => ['timestampValue' => '2025-04-01T08:20:00.000Z'],
        'duracionSegundos' => ['integerValue' => '1200'],
        'distanciaMetros' => ['doubleValue' => 900.0],
        'velocidadMaximaKmh' => ['doubleValue' => 6.0],
        'ruta' => ['arrayValue' => ['values' => [
            ['geoPointValue' => ['latitude' => 41.1, 'longitude' => 2.2]],
        ]]],
    ]);

    $json = $paseos->toJson('viejo', $stored);
    assertSame(41.1, $json['ruta'][0]['lat']);
    assertSame(null, $json['ruta'][0]['timestamp'], 'los paseos viejos no traen hora por punto');
});

echo "\n== Support\\Jwt ==\n";
check('firma y verifica RS256', function (): void {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    assertTrue($key !== false, 'openssl no genero la clave');
    openssl_pkey_export($key, $privatePem);
    $publicPem = openssl_pkey_get_details($key)['key'];

    $token = Jwt::signRs256(['iss' => 'test@example.com', 'exp' => time() + 60], $privatePem);
    $parsed = Jwt::parse($token);

    assertTrue($parsed !== null, 'no se pudo parsear');
    assertSame('RS256', $parsed['header']['alg']);
    assertSame('test@example.com', $parsed['claims']['iss']);
    assertTrue(Jwt::verifyRs256($parsed['signingInput'], $parsed['signature'], $publicPem), 'firma invalida');

    // Un token manipulado no debe pasar.
    assertTrue(
        !Jwt::verifyRs256($parsed['signingInput'] . 'x', $parsed['signature'], $publicPem),
        'acepto un token manipulado'
    );
});

check('base64url va y vuelve', function (): void {
    $raw = random_bytes(64);
    assertSame($raw, Json::base64UrlDecode(Json::base64UrlEncode($raw)));
});

echo "\n";
echo $failed === 0
    ? "TODO OK: $passed pruebas\n"
    : "$failed FALLOS de " . ($passed + $failed) . " pruebas\n";

exit($failed === 0 ? 0 : 1);
