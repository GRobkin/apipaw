<?php

declare(strict_types=1);

namespace PawLife\Notifications;

use DateTimeImmutable;
use PawLife\Http\HttpException;

/** Evita que una cuenta llene la cola compartida de avisos. */
final class ReminderRateLimiter
{
    private const WINDOW_SECONDS = 2 * 60 * 60;
    private const MAX_WRITES = 100;
    private const MAX_CONFLICT_RETRIES = 5;

    public function __construct(private readonly ReminderStore $db)
    {
    }

    public function consume(string $uid, ?DateTimeImmutable $clock = null): void
    {
        $now = $clock ?? new DateTimeImmutable('now');
        $window = intdiv($now->getTimestamp(), self::WINDOW_SECONDS);
        $path = self::documentPath($uid);
        $id = substr($path, strlen('system/'));

        for ($attempt = 0; $attempt < self::MAX_CONFLICT_RETRIES; $attempt++) {
            $existing = $this->db->getDocumentVersioned($path);
            $count = $existing !== null && ($existing['data']['window'] ?? null) === $window
                ? (int) ($existing['data']['count'] ?? 0) : 0;
            if ($count >= self::MAX_WRITES) {
                throw new HttpException(429, 'Demasiados cambios de recordatorios. Intenta de nuevo mas tarde.');
            }
            $owner = str_starts_with($uid, 'admin-notification:') ? substr($uid, strlen('admin-notification:')) : $uid;
            $data = ['userId' => $owner, 'window' => $window, 'count' => $count + 1, 'actualizadoEn' => $now];
            try {
                if ($existing === null) {
                    $this->db->createDocument('system', $data, $id);
                } else {
                    $this->db->patchDocument($path, $data, $existing['updateTime']);
                }
                return;
            } catch (HttpException $e) {
                if ($e->status() !== 409) throw $e;
            }
        }
        throw new HttpException(503, 'No se pudo reservar el cambio del recordatorio. Intenta de nuevo.');
    }

    public static function documentPath(string $uid): string
    {
        return 'system/reminder-quota-' . hash('sha256', $uid);
    }
}
