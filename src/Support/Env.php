<?php

declare(strict_types=1);

namespace PawLife\Support;

use RuntimeException;

final class Env
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    /**
     * Igual que get(), pero revienta si falta. Se usa para la configuracion
     * sin la cual la API no puede hacer absolutamente nada, para que el fallo
     * salga claro en el arranque y no como un 500 opaco a mitad de request.
     */
    public static function mustGet(string $key): string
    {
        $value = self::get($key);

        if ($value === null) {
            throw new RuntimeException("Falta la variable de entorno $key.");
        }

        return $value;
    }
}
