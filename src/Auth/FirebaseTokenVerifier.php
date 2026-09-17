<?php

declare(strict_types=1);

namespace PawLife\Auth;

use PawLife\Http\HttpException;
use PawLife\Support\Cache;
use PawLife\Support\Env;
use PawLife\Support\HttpClient;
use PawLife\Support\Json;
use PawLife\Support\Jwt;
use RuntimeException;

/**
 * Verifica los ID token que emite Firebase Auth en el cliente Flutter.
 *
 * Esta es la pieza que sustituye a las reglas de Firestore: antes el cliente
 * escribia directo y `request.auth.uid` garantizaba que nadie tocara datos
 * ajenos. Ahora quien escribe es el backend con una service account, que se
 * salta las reglas, asi que el uid tiene que salir de aqui -- de un token
 * firmado por Google que el cliente no puede falsificar.
 *
 * Comprobaciones, segun la documentacion de Firebase:
 *  - firma RS256 valida contra el certificado publico cuyo "kid" declara el token;
 *  - aud == projectId;
 *  - iss == https://securetoken.google.com/{projectId};
 *  - exp en el futuro, iat/auth_time en el pasado;
 *  - sub no vacio (es el uid).
 */
final class FirebaseTokenVerifier
{
    private const CERTS_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    /** Margen para el desfase de reloj entre Google y el contenedor de Vercel. */
    private const LEEWAY_SECONDS = 60;

    public function __construct(private readonly string $projectId)
    {
    }

    public static function fromEnv(): self
    {
        return new self(Env::mustGet('FIREBASE_PROJECT_ID'));
    }

    /**
     * @throws HttpException 401 si el token falta, esta caducado o no es valido.
     */
    public function verify(?string $token): AuthenticatedUser
    {
        if ($token === null || $token === '') {
            throw HttpException::unauthorized(
                'Falta el header Authorization: Bearer <ID token de Firebase>.',
            );
        }

        $parsed = Jwt::parse($token);
        if ($parsed === null) {
            throw HttpException::unauthorized('El token no tiene formato JWT.');
        }

        $header = $parsed['header'];
        $claims = $parsed['claims'];

        if (($header['alg'] ?? null) !== 'RS256') {
            throw HttpException::unauthorized('El token debe estar firmado con RS256.');
        }

        $kid = $header['kid'] ?? null;
        if (!is_string($kid) || $kid === '') {
            throw HttpException::unauthorized('El token no declara el certificado con el que se firmo (kid).');
        }

        $certificate = $this->certificateFor($kid);
        if (!Jwt::verifyRs256($parsed['signingInput'], $parsed['signature'], $certificate)) {
            throw HttpException::unauthorized('La firma del token no es valida.');
        }

        $this->assertClaims($claims);

        $firebase = is_array($claims['firebase'] ?? null) ? $claims['firebase'] : [];

        return new AuthenticatedUser(
            uid: (string) $claims['sub'],
            email: isset($claims['email']) ? (string) $claims['email'] : null,
            name: isset($claims['name']) ? (string) $claims['name'] : null,
            pictureUrl: isset($claims['picture']) ? (string) $claims['picture'] : null,
            signInProvider: (string) ($firebase['sign_in_provider'] ?? 'unknown'),
        );
    }

    /** @param array<string, mixed> $claims */
    private function assertClaims(array $claims): void
    {
        $now = time();

        if (($claims['aud'] ?? null) !== $this->projectId) {
            throw HttpException::unauthorized(
                'El token pertenece a otro proyecto de Firebase.',
            );
        }

        if (($claims['iss'] ?? null) !== "https://securetoken.google.com/{$this->projectId}") {
            throw HttpException::unauthorized('El emisor del token no es Firebase Auth.');
        }

        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            throw HttpException::unauthorized('El token no trae uid.');
        }

        $exp = (int) ($claims['exp'] ?? 0);
        if ($exp + self::LEEWAY_SECONDS <= $now) {
            throw HttpException::unauthorized('El token ha caducado, pide uno nuevo.');
        }

        $iat = (int) ($claims['iat'] ?? 0);
        if ($iat - self::LEEWAY_SECONDS > $now) {
            throw HttpException::unauthorized('El token dice haberse emitido en el futuro.');
        }
    }

    /**
     * Certificado x509 publico correspondiente al kid. Google los rota cada
     * pocas horas, por eso se respeta el max-age que manda en Cache-Control en
     * vez de cachearlos indefinidamente.
     */
    private function certificateFor(string $kid): string
    {
        $certificates = Cache::get('firebase-certs');

        if (!is_array($certificates) || !isset($certificates[$kid])) {
            $certificates = $this->fetchCertificates();
        }

        if (!isset($certificates[$kid]) || !is_string($certificates[$kid])) {
            throw HttpException::unauthorized(
                'El token se firmo con una clave que Google ya no publica.',
            );
        }

        return $certificates[$kid];
    }

    /** @return array<string, string> */
    private function fetchCertificates(): array
    {
        try {
            $response = HttpClient::send('GET', self::CERTS_URL);
        } catch (RuntimeException $e) {
            // Sin los certificados no se puede verificar nada, pero esto no es
            // culpa del cliente: es un problema de salida a internet del
            // servidor. Un 503 con el motivo ahorra mucho tiempo, porque el
            // sintoma (nadie puede autenticarse) no apunta a esto.
            throw new HttpException(
                503,
                'No se pudo contactar con Google para verificar el token: ' . $e->getMessage(),
                previous: $e,
            );
        }

        if ($response['status'] !== 200) {
            throw new HttpException(
                503,
                'Google devolvio ' . $response['status'] . ' al pedir los certificados de Firebase.',
            );
        }

        $certificates = Json::decodeObject($response['body']) ?? [];

        $ttl = 3600;
        $cacheControl = $response['headers']['cache-control'] ?? '';
        if (preg_match('/max-age=(\d+)/', $cacheControl, $m)) {
            $ttl = max(60, (int) $m[1]);
        }

        Cache::put('firebase-certs', $certificates, $ttl);

        /** @var array<string, string> $certificates */
        return $certificates;
    }
}
