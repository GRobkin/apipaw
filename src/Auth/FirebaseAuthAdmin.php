<?php

declare(strict_types=1);

namespace PawLife\Auth;

use PawLife\Firestore\AccessTokenProvider;
use PawLife\Http\HttpException;
use PawLife\Support\Env;
use PawLife\Support\HttpClient;

final class FirebaseAuthAdmin
{
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
