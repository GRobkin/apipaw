<?php

declare(strict_types=1);

namespace PawLife\Http;

use RuntimeException;
use Throwable;

/**
 * Error que ya sabe con que codigo HTTP quiere salir. El front controller lo
 * captura y lo convierte en una respuesta JSON; cualquier otra excepcion se
 * trata como un 500 y no se le ensena el detalle al cliente.
 */
class HttpException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        private readonly int $status,
        string $message,
        private readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }

    /** @param array<string, mixed> $details */
    public static function badRequest(string $message, array $details = []): self
    {
        return new self(400, $message, $details);
    }

    public static function unauthorized(string $message): self
    {
        return new self(401, $message);
    }

    public static function forbidden(string $message): self
    {
        return new self(403, $message);
    }

    public static function notFound(string $message): self
    {
        return new self(404, $message);
    }

    public static function methodNotAllowed(string $message): self
    {
        return new self(405, $message);
    }
}
