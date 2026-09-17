<?php

declare(strict_types=1);

namespace PawLife\Auth;

use PawLife\Support\Env;
use PawLife\Support\Json;
use RuntimeException;

/**
 * Credenciales de la service account de Firebase, leidas de la variable de
 * entorno FIREBASE_SERVICE_ACCOUNT.
 *
 * Se acepta tanto el JSON tal cual como su version en base64. El base64 es lo
 * recomendado en Vercel: el JSON lleva "\n" literales dentro de private_key y
 * segun como se pegue en la UI llegan destrozados, lo que hace que openssl
 * rechace la clave con un error que no dice nada.
 */
final class ServiceAccount
{
    private function __construct(
        public readonly string $clientEmail,
        public readonly string $privateKey,
        public readonly string $projectId,
        public readonly string $tokenUri,
    ) {
    }

    public static function fromEnv(): self
    {
        $raw = Env::mustGet('FIREBASE_SERVICE_ACCOUNT');

        $data = Json::decodeObject($raw);
        if ($data === null) {
            $decoded = base64_decode($raw, true);
            $data = $decoded === false ? null : Json::decodeObject($decoded);
        }

        if ($data === null) {
            throw new RuntimeException(
                'FIREBASE_SERVICE_ACCOUNT no contiene un JSON valido (ni directo ni en base64).',
            );
        }

        foreach (['client_email', 'private_key'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') {
                throw new RuntimeException("A la service account le falta el campo $field.");
            }
        }

        // Si el JSON viajo por una variable de entorno que escapo los saltos de
        // linea, la clave llega con "\n" de dos caracteres en vez del salto.
        $privateKey = str_replace('\n', "\n", (string) $data['private_key']);

        return new self(
            clientEmail: (string) $data['client_email'],
            privateKey: $privateKey,
            projectId: (string) ($data['project_id'] ?? Env::mustGet('FIREBASE_PROJECT_ID')),
            tokenUri: (string) ($data['token_uri'] ?? 'https://oauth2.googleapis.com/token'),
        );
    }
}
