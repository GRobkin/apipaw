<?php

declare(strict_types=1);

namespace PawLife\Support;

/**
 * Cache de andar por casa para cosas que caducan: el access token de Google y
 * los certificados con los que se verifican los ID token de Firebase.
 *
 * Dos niveles, porque en serverless no hay proceso largo al que agarrarse:
 *  - estatico en memoria, valido mientras dure la invocacion (y las siguientes
 *    si Vercel reutiliza el contenedor "caliente");
 *  - /tmp, lo unico con escritura en el runtime, que sobrevive entre
 *    invocaciones del mismo contenedor.
 *
 * Perder la cache nunca es un error: simplemente se vuelve a pedir el dato.
 */
final class Cache
{
    /** @var array<string, array{expiresAt: int, value: mixed}> */
    private static array $memory = [];

    public static function get(string $key): mixed
    {
        $now = time();

        $entry = self::$memory[$key] ?? null;
        if ($entry !== null && $entry['expiresAt'] > $now) {
            return $entry['value'];
        }

        $path = self::path($key);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $entry = Json::decodeObject($raw);
        if ($entry === null || !isset($entry['expiresAt']) || (int) $entry['expiresAt'] <= $now) {
            return null;
        }

        self::$memory[$key] = ['expiresAt' => (int) $entry['expiresAt'], 'value' => $entry['value'] ?? null];

        return $entry['value'] ?? null;
    }

    public static function put(string $key, mixed $value, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            return;
        }

        $expiresAt = time() + $ttlSeconds;
        self::$memory[$key] = ['expiresAt' => $expiresAt, 'value' => $value];

        // Si /tmp no deja escribir seguimos con la cache en memoria: es una
        // optimizacion, no un requisito.
        @file_put_contents(
            self::path($key),
            Json::encode(['expiresAt' => $expiresAt, 'value' => $value]),
            LOCK_EX,
        );
    }

    private static function path(string $key): string
    {
        return sys_get_temp_dir() . '/pawlife-' . sha1($key) . '.json';
    }
}
