<?php

declare(strict_types=1);

namespace PawLife\Notifications;

use PawLife\Auth\ServiceAccount;
use PawLife\Firestore\AccessTokenProvider;
use PawLife\Http\HttpException;
use PawLife\Support\Env;
use PawLife\Support\HttpClient;
use PawLife\Support\Json;
use RuntimeException;

final class FcmSender
{
    public function send(string $deviceToken, string $reminderId, string $petId, string $type, string $message): void
    {
        $serviceAccount = ServiceAccount::fromEnv();
        $projectId = Env::get('FIREBASE_PROJECT_ID', $serviceAccount->projectId);
        try {
            $token = (new AccessTokenProvider($serviceAccount, AccessTokenProvider::MESSAGING_SCOPE))->token();
            $response = HttpClient::send(
                'POST',
                'https://fcm.googleapis.com/v1/projects/' . rawurlencode((string) $projectId) . '/messages:send',
                ['Authorization' => 'Bearer ' . $token],
                ['message' => [
                    'token' => $deviceToken,
                    'notification' => ['title' => 'PawLife', 'body' => $message],
                    'data' => [
                        'recordatorioId' => $reminderId,
                        'mascotaId' => $petId,
                        'tipo' => $type,
                    ],
                    'android' => ['priority' => 'HIGH'],
                    'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
                ]],
                10,
            );
        } catch (RuntimeException $e) {
            throw new HttpException(503, 'No se pudo contactar con FCM.', previous: $e);
        }
        if ($response['status'] !== 200) {
            $payload = Json::decodeObject($response['body']) ?? [];
            $code = (string) ($payload['error']['status'] ?? 'UNKNOWN');
            throw new HttpException(502, 'FCM rechazo el mensaje: ' . $code);
        }
    }
}
