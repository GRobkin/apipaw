<?php

declare(strict_types=1);

namespace PawLife\Notifications;

use DateTimeImmutable;
use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;

final class PushDispatcher
{
    private const LOOKBACK_HOURS = 2;
    private const MAX_SENDS = 10;
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly FirestoreClient $db,
        private readonly FcmSender $fcm,
    ) {
    }

    /** @return array{recordatorios: int, candidatos: int, enviados: int, fallidos: int, omitidos: int} */
    public function dispatch(?DateTimeImmutable $clock = null, bool $dryRun = false): array
    {
        $now = $clock ?? new DateTimeImmutable('now');
        $start = $now->modify('-' . self::LOOKBACK_HOURS . ' hours');
        $reminders = $this->db->queryDateWindow('recordatorios', 'fecha', $start, $now);
        $stats = ['recordatorios' => count($reminders), 'candidatos' => 0, 'enviados' => 0, 'fallidos' => 0, 'omitidos' => 0];
        $devicesByUser = [];
        $attempted = 0;

        foreach ($reminders as $reminder) {
            $data = $reminder['data'];
            $uid = $data['userId'] ?? null;
            $due = $data['fecha'] ?? null;
            if (($data['completado'] ?? false) === true || !is_string($uid) || $uid === '' || !$due instanceof DateTimeImmutable) {
                $stats['omitidos']++;
                continue;
            }
            if (!isset($devicesByUser[$uid])) {
                $devicesByUser[$uid] = $this->db->queryDocuments('dispositivos', ['userId' => $uid]);
            }
            foreach ($devicesByUser[$uid] as $device) {
                $info = $device['data'];
                $token = $info['token'] ?? null;
                if (!is_string($token) || $token === '' || ($info['activo'] ?? true) === false || ($info['demo'] ?? false) === true) {
                    $stats['omitidos']++;
                    continue;
                }
                $stats['candidatos']++;
                if ($dryRun) continue;
                if ($attempted >= self::MAX_SENDS) return $stats;
                $noticeId = self::noticeId($reminder['id'], $token, $due);
                if (!$this->claim($noticeId, $reminder['id'], $uid, $token, $due, $now)) {
                    $stats['omitidos']++;
                    continue;
                }
                $attempted++;
                try {
                    $this->fcm->send(
                        $token,
                        $reminder['id'],
                        (string) ($data['mascotaId'] ?? ''),
                        (string) ($data['tipo'] ?? ''),
                        substr((string) ($data['mensaje'] ?? 'Tenes un cuidado pendiente.'), 0, 240),
                    );
                    $this->db->patchDocument('notificaciones/' . $noticeId, [
                        'estado' => 'enviada', 'enviadaEn' => new DateTimeImmutable('now'),
                        'actualizadoEn' => new DateTimeImmutable('now'),
                    ]);
                    $stats['enviados']++;
                } catch (\Throwable $e) {
                    $this->db->patchDocument('notificaciones/' . $noticeId, [
                        'estado' => 'fallida',
                        'proximoIntento' => (new DateTimeImmutable('now'))->modify('+10 minutes'),
                        'ultimoError' => substr($e->getMessage(), 0, 160),
                        'actualizadoEn' => new DateTimeImmutable('now'),
                    ]);
                    $stats['fallidos']++;
                }
            }
        }
        return $stats;
    }

    public static function noticeId(string $reminderId, string $deviceToken, DateTimeImmutable $due): string
    {
        return 'push_' . hash('sha256', $reminderId . "\0" . $deviceToken . "\0" . $due->format('U.u'));
    }

    private function claim(string $noticeId, string $reminderId, string $uid, string $deviceToken, DateTimeImmutable $due, DateTimeImmutable $now): bool
    {
        $path = 'notificaciones/' . $noticeId;
        $existing = $this->db->getDocumentVersioned($path);
        if ($existing === null) {
            try {
                $this->db->createDocument('notificaciones', [
                    'userId' => $uid,
                    'recordatorioId' => $reminderId,
                    'deviceTokenHash' => hash('sha256', $deviceToken),
                    'canal' => 'push',
                    'estado' => 'procesando',
                    'intentos' => 1,
                    'programadaPara' => $due,
                    'leaseHasta' => $now->modify('+2 minutes'),
                    'creadoEn' => $now,
                    'actualizadoEn' => $now,
                ], $noticeId);
                return true;
            } catch (HttpException $e) {
                if ($e->status() === 409) return false;
                throw $e;
            }
        }

        $data = $existing['data'];
        if (($data['estado'] ?? '') === 'enviada' || (int) ($data['intentos'] ?? 0) >= self::MAX_ATTEMPTS) return false;
        foreach (['leaseHasta', 'proximoIntento'] as $field) {
            if (($data[$field] ?? null) instanceof DateTimeImmutable && $data[$field] > $now) return false;
        }
        try {
            $this->db->patchDocument($path, [
                'estado' => 'procesando',
                'intentos' => (int) ($data['intentos'] ?? 0) + 1,
                'leaseHasta' => $now->modify('+2 minutes'),
                'actualizadoEn' => $now,
            ], $existing['updateTime']);
            return true;
        } catch (HttpException $e) {
            if ($e->status() === 409) return false;
            throw $e;
        }
    }
}
