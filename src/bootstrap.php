<?php

declare(strict_types=1);

// Autoloader propio en vez de Composer.
//
// El runtime de PHP en Vercel es comunitario (vercel-php) y su paso de
// `composer install` es una fuente de sorpresas en el build. Como la API no
// necesita ninguna libreria externa -- la firma y verificacion de JWT se hace
// con openssl, que viene de serie -- salimos del paso con un PSR-4 de diez
// lineas y cero dependencias que instalar.
spl_autoload_register(static function (string $class): void {
    $prefix = 'PawLife\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

// En local las variables viven en un .env; en Vercel las inyecta la
// plataforma y este archivo no existe.
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Quitamos comillas envolventes si las hay, para que el valor real no
        // se lleve las comillas puestas.
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}
