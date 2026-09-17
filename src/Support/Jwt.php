<?php

declare(strict_types=1);

namespace PawLife\Support;

use RuntimeException;

/**
 * Firma y verificacion de JWT RS256 con openssl.
 *
 * Solo se necesita RS256: es lo que usan tanto los ID token de Firebase Auth
 * (verificacion) como el "assertion" que se le manda a Google para conseguir
 * un access token de Firestore (firma).
 */
final class Jwt
{
    /**
     * @param array<string, mixed> $claims
     */
    public static function signRs256(array $claims, string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new RuntimeException(
                'La private_key de la service account no es valida. Revisa que los saltos de linea no se hayan perdido al copiarla.',
            );
        }

        $header = Json::base64UrlEncode(Json::encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = Json::base64UrlEncode(Json::encode($claims));
        $signingInput = "$header.$payload";

        $signature = '';
        if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No se pudo firmar el JWT.');
        }

        return $signingInput . '.' . Json::base64UrlEncode($signature);
    }

    /**
     * Separa un JWT sin verificar nada. Sirve para leer el "kid" de la cabecera
     * y saber con que clave publica hay que comprobar la firma.
     *
     * @return array{header: array<string, mixed>, claims: array<string, mixed>, signingInput: string, signature: string}|null
     */
    public static function parse(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$rawHeader, $rawPayload, $rawSignature] = $parts;

        $header = Json::decodeObject(Json::base64UrlDecode($rawHeader));
        $claims = Json::decodeObject(Json::base64UrlDecode($rawPayload));
        if ($header === null || $claims === null) {
            return null;
        }

        return [
            'header' => $header,
            'claims' => $claims,
            'signingInput' => "$rawHeader.$rawPayload",
            'signature' => Json::base64UrlDecode($rawSignature),
        ];
    }

    public static function verifyRs256(string $signingInput, string $signature, string $publicKeyOrCertPem): bool
    {
        $key = openssl_pkey_get_public($publicKeyOrCertPem);
        if ($key === false) {
            return false;
        }

        return openssl_verify($signingInput, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }
}
