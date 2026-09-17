<?php

declare(strict_types=1);

namespace PawLife\Http;

use PawLife\Support\Json;

final class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers  nombres en minusculas
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        private readonly array $headers,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        // Normalizamos: sin barra final y siempre empezando por "/", para que
        // "/api/mascotas" y "/api/mascotas/" lleguen a la misma ruta.
        $path = '/' . trim($path, '/');

        $raw = file_get_contents('php://input');
        $body = is_string($raw) ? (Json::decodeObject($raw) ?? []) : [];

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        // Algunos SAPI dejan estas dos fuera del prefijo HTTP_.
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        /** @var array<string, string> $query */
        $query = array_map(static fn ($v) => is_array($v) ? (string) reset($v) : (string) $v, $_GET);

        return new self($method, $path, $query, $body, $headers);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Token del header Authorization, sin el prefijo "Bearer".
     */
    public function bearerToken(): ?string
    {
        $value = $this->header('authorization');
        if ($value === null || !preg_match('/^Bearer\s+(.+)$/i', trim($value), $m)) {
            return null;
        }

        return trim($m[1]);
    }

    public function queryParam(string $name, ?string $default = null): ?string
    {
        $value = $this->query[$name] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }
}
