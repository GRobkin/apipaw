<?php

declare(strict_types=1);

namespace PawLife\Http;

use PawLife\Support\Env;
use PawLife\Support\Json;

final class Response
{
    /** @param array<string, mixed>|list<mixed>|null $payload */
    public function __construct(
        public readonly int $status,
        public readonly array|null $payload = null,
    ) {
    }

    /** @param array<string, mixed>|list<mixed> $payload */
    public static function ok(array $payload): self
    {
        return new self(200, $payload);
    }

    /** @param array<string, mixed> $payload */
    public static function created(array $payload): self
    {
        return new self(201, $payload);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    /** @param array<string, mixed> $details */
    public static function error(int $status, string $message, array $details = []): self
    {
        $payload = ['error' => ['status' => $status, 'message' => $message]];
        if ($details !== []) {
            $payload['error']['details'] = $details;
        }

        return new self($status, $payload);
    }

    public function send(?string $origin = null): void
    {
        http_response_code($this->status);
        self::sendCorsHeaders($origin);

        if ($this->status === 204 || $this->payload === null) {
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo Json::encode($this->payload);
    }

    /**
     * CORS solo hace falta si algun dia se compila para Flutter Web; en
     * Android/iOS no hay preflight. Se deja configurado igualmente porque
     * depurar un fallo de CORS desde cero cuesta mas que dejarlo puesto.
     */
    public static function sendCorsHeaders(?string $origin = null): void
    {
        $allowed = Env::get('ALLOWED_ORIGINS', '*') ?? '*';

        if ($allowed === '*') {
            header('Access-Control-Allow-Origin: *');
        } elseif ($origin !== null) {
            $list = array_map('trim', explode(',', $allowed));
            if (in_array($origin, $list, true)) {
                header("Access-Control-Allow-Origin: $origin");
                // Sin esto, un proxy o el propio navegador podria servirle a
                // otro origen la respuesta cacheada con la cabecera ajena.
                header('Vary: Origin');
            }
        }

        header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Max-Age: 86400');
    }
}
