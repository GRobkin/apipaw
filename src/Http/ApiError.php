<?php
declare(strict_types=1);

namespace PawLife\Http;

use Throwable;

/** Nunca registra el mensaje/trace, la URL, tokens, GPS ni contenido del usuario. */
final class ApiError
{
    public static function response(Throwable $error, string $requestId): Response
    {
        $status = $error instanceof HttpException ? $error->status() : 500;
        $code = $error instanceof HttpException ? $error->errorCode : null;
        $code ??= match ($status) {
            400 => 'INVALID_REQUEST', 401 => 'AUTHENTICATION_REQUIRED',
            403 => 'FORBIDDEN', 404 => 'NOT_FOUND', 405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT', 413 => 'REQUEST_TOO_LARGE', 422 => 'VALIDATION_FAILED',
            429 => 'RATE_LIMITED', 502, 503, 504 => 'DEPENDENCY_UNAVAILABLE',
            default => 'INTERNAL_ERROR',
        };
        $message = $status < 500 ? $error->getMessage()
            : ($status === 500 ? 'Error interno del servidor.' : 'Servicio temporalmente no disponible. Intentá de nuevo.');
        $details = $error instanceof HttpException && $status < 500 ? $error->details() : [];
        $response = Response::error($status, $message, $details, $code);
        return new Response($response->status, ['error' => [
            ...$response->payload['error'], 'requestId' => $requestId,
        ]]);
    }

    public static function logRecord(Throwable $error, string $requestId): string
    {
        return json_encode([
            'service' => 'pawlife-api', 'requestId' => $requestId,
            'exceptionType' => $error::class,
            'status' => $error instanceof HttpException ? $error->status() : 500,
            'code' => $error instanceof HttpException ? $error->errorCode : null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
