<?php

declare(strict_types=1);

namespace PawLife\Firestore;

use PawLife\Auth\ServiceAccount;
use PawLife\Support\Cache;
use PawLife\Support\HttpClient;
use PawLife\Support\Json;
use PawLife\Support\Jwt;
use RuntimeException;

/**
 * Consigue un access token OAuth2 para llamar a la API REST de Firestore.
 *
 * Es el flujo "JWT bearer" de service account: se firma un JWT con la clave
 * privada y se cambia por un access token en el endpoint de token de Google.
 * Lo hacemos a mano en vez de con google/auth porque ese paquete -- y el SDK
 * de Firestore entero -- necesita la extension gRPC, que el runtime de PHP de
 * Vercel no trae.
 */
final class AccessTokenProvider
{
    private const SCOPE = 'https://www.googleapis.com/auth/datastore';

    /** Duracion que pedimos. Google devuelve como mucho una hora. */
    private const LIFETIME_SECONDS = 3600;

    public function __construct(private readonly ServiceAccount $serviceAccount)
    {
    }

    public function token(): string
    {
        $cacheKey = 'google-access-token-' . sha1($this->serviceAccount->clientEmail . self::SCOPE);

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $assertion = Jwt::signRs256([
            'iss' => $this->serviceAccount->clientEmail,
            'scope' => self::SCOPE,
            'aud' => $this->serviceAccount->tokenUri,
            'iat' => $now,
            'exp' => $now + self::LIFETIME_SECONDS,
        ], $this->serviceAccount->privateKey);

        $response = HttpClient::send(
            'POST',
            $this->serviceAccount->tokenUri,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]),
        );

        $payload = Json::decodeObject($response['body']) ?? [];

        if ($response['status'] !== 200 || !isset($payload['access_token'])) {
            $detail = (string) ($payload['error_description'] ?? $payload['error'] ?? $response['body']);
            throw new RuntimeException("Google rechazo las credenciales de la service account: $detail");
        }

        $token = (string) $payload['access_token'];
        $expiresIn = (int) ($payload['expires_in'] ?? self::LIFETIME_SECONDS);

        // Caducamos el token un minuto antes que Google para no usar uno que
        // expire justo mientras viaja la peticion a Firestore.
        Cache::put($cacheKey, $token, max(60, $expiresIn - 60));

        return $token;
    }
}
