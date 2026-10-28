<?php
declare(strict_types=1);

namespace PawLife\Resources;

use PawLife\Http\HttpException;

/**
 * Comprueba, en el entorno donde corre, con qué se pueden procesar las fotos:
 * extensión GD o el decodificador de Node (`tool/jpeg-codec.cjs` + jpeg-js).
 * Sirve para confirmar el despliegue real en lugar de suponerlo: el runtime de
 * Vercel no publica GD, y que `proc_open` y `node` existan en producción no
 * está garantizado. Solo devuelve booleanos y la versión de PHP.
 */
final class PhotoDiagnostics
{
    /** JPEG sintético de 8x8, sin datos personales. */
    private const SAMPLE = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gNzAK/9sAQwAKBwcIBwYKCAgICwoKCw4YEA4NDQ4dFRYRGCMfJSQiHyIhJis3LyYpNCkhIjBBMTQ5Oz4+PiUuRElDPEg3PT47/9sAQwEKCwsODQ4cEBAcOygiKDs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7/8AAEQgACAAIAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8AwKKKKk+dP//Z';

    /** @return array<string, mixed> */
    public static function report(): array
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $root = dirname(__DIR__, 2);
        $roundTrip = false;
        $error = null;
        try {
            $out = NodePhotoDecoder::decode((string) base64_decode(self::SAMPLE, true));
            $roundTrip = str_starts_with($out, "\xFF\xD8");
        } catch (HttpException $e) {
            $error = $e->errorCode ?? 'ERROR';
        }
        $gd = PhotoInput::hasDecoder();
        return [
            'php' => PHP_VERSION,
            'gd' => $gd,
            'procOpen' => function_exists('proc_open') && !in_array('proc_open', $disabled, true),
            'codecFile' => is_file($root . '/tool/jpeg-codec.cjs'),
            'jpegJs' => is_dir($root . '/node_modules/jpeg-js'),
            'nodeRoundTrip' => $roundTrip,
            'nodeError' => $error,
            // Con alguno de los dos caminos las fotos se pueden validar y recodificar.
            'fotosDisponibles' => $gd || $roundTrip,
        ];
    }
}
