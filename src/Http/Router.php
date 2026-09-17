<?php

declare(strict_types=1);

namespace PawLife\Http;

/**
 * Router minimo por segmentos. Las rutas se declaran con marcadores entre
 * llaves ("/api/mascotas/{mascotaId}/vacunas/{id}") y lo que caiga en cada
 * marcador llega al handler como parametro.
 */
final class Router
{
    /** @var list<array{method: string, segments: list<string>, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'segments' => self::split($pattern),
            'handler' => $handler,
        ];
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    /**
     * Ejecuta la ruta que corresponda.
     *
     * @throws HttpException 404 si ninguna ruta encaja, 405 si encaja el path
     *                       pero no el metodo.
     */
    public function dispatch(Request $request): Response
    {
        $segments = self::split($request->path);
        $pathMatched = false;

        foreach ($this->routes as $route) {
            $params = self::match($route['segments'], $segments);
            if ($params === null) {
                continue;
            }

            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            return ($route['handler'])($request, $params);
        }

        if ($pathMatched) {
            throw HttpException::methodNotAllowed(
                "El metodo {$request->method} no esta permitido en {$request->path}.",
            );
        }

        throw HttpException::notFound("No existe la ruta {$request->path}.");
    }

    /** @return list<string> */
    private static function split(string $path): array
    {
        $trimmed = trim($path, '/');

        return $trimmed === '' ? [] : explode('/', $trimmed);
    }

    /**
     * @param list<string> $pattern
     * @param list<string> $actual
     *
     * @return array<string, string>|null  null si no encaja.
     */
    private static function match(array $pattern, array $actual): ?array
    {
        if (count($pattern) !== count($actual)) {
            return null;
        }

        $params = [];
        foreach ($pattern as $i => $segment) {
            if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
                $value = rawurldecode($actual[$i]);
                if ($value === '') {
                    return null;
                }
                $params[substr($segment, 1, -1)] = $value;
                continue;
            }

            if ($segment !== $actual[$i]) {
                return null;
            }
        }

        return $params;
    }
}
