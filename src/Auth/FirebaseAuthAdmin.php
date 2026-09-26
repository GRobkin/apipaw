<?php

declare(strict_types=1);

namespace PawLife\Auth;

use PawLife\Firestore\AccessTokenProvider;
use PawLife\Http\HttpException;
use PawLife\Support\Env;
use PawLife\Support\HttpClient;
use PawLife\Support\Json;
use RuntimeException;

final class FirebaseAuthAdmin
{
    /** Comprueba que la cuenta sigue activa y que la sesion no fue revocada. */
    public static function assertActive(string $uid, int $authTime): void
    {
        $serviceAccount = ServiceAccount::fromEnv();
        $projectId = Env::get('FIREBASE_PROJECT_ID', $serviceAccount->projectId);
        try {
            $token = (new AccessTokenProvider($serviceAccount, AccessTokenProvider::AUTH_SCOPE))->token();
            $response = HttpClient::send(
                'POST',
                'https://identitytoolkit.googleapis.com/v1/projects/' . rawurlencode((string) $projectId) . '/accounts:lookup',
                ['Authorization' => 'Bearer ' . $token],
                ['localId' => [$uid]],
                15,
            );
        } catch (RuntimeException $e) {
            throw new HttpException(503, 'No se pudo comprobar el estado de la cuenta de Firebase.', previous: $e);
        }
        if ($response['status'] !== 200) {
            throw new HttpException(503, 'Firebase no pudo comprobar el estado de la cuenta.');
        }
        self::assertLookupResult($uid, $authTime, Json::decodeObject($response['body']) ?? []);
    }

    /** @param array<string, mixed> $result */
    public static function assertLookupResult(string $uid, int $authTime, array $result): void
    {
        $users = $result['users'] ?? [];
        $account = is_array($users) ? ($users[0] ?? null) : null;
        if (!is_array($account) || ($account['localId'] ?? null) !== $uid || ($account['disabled'] ?? false) === true) {
            throw HttpException::unauthorized('La cuenta ya no esta activa.');
        }
        if ($authTime < (int) ($account['validSince'] ?? 0)) {
            throw HttpException::unauthorized('La sesion fue revocada; inicia sesion de nuevo.');
        }
    }

    public static function deleteUser(string $uid): void
    {
        $serviceAccount = ServiceAccount::fromEnv();
        $projectId = Env::get('FIREBASE_PROJECT_ID', $serviceAccount->projectId);
        $token = (new AccessTokenProvider($serviceAccount, AccessTokenProvider::AUTH_SCOPE))->token();
        $response = HttpClient::send(
            'POST',
            'https://identitytoolkit.googleapis.com/v1/projects/' . rawurlencode((string) $projectId) . '/accounts:delete',
            ['Authorization' => 'Bearer ' . $token],
            ['localId' => $uid],
            15,
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new HttpException(502, 'No se pudo eliminar la cuenta de Firebase Authentication.');
        }
    }
}
