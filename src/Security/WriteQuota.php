<?php
declare(strict_types=1);

namespace PawLife\Security;

use DateTimeImmutable;
use PawLife\Firestore\AtomicStore;
use PawLife\Http\HttpException;

/** Versioned quotas: registration reserves atomically; photo attempts reserve before CPU work. */
final class WriteQuota
{
    public function __construct(private readonly AtomicStore $db) {}

    public function prepareDeviceRegistration(string $uid, ?DateTimeImmutable $now = null): array
    {
        return $this->prepare($uid, 'device', 'deviceRegistration', 60, $now);
    }

    public function reservePhotoAttempt(string $uid): void
    {
        $this->reserve($uid, 'photo', 'photoAttempt', 30);
    }

    public function reservePrivacy(string $uid, string $operation): void
    {
        $maximum = match ($operation) { 'exportStart' => 3, 'exportPage' => 3000, 'privacyRequest' => 10, 'preferences' => 60, default => throw new \InvalidArgumentException('Cuota desconocida.') };
        $this->reserve($uid, $operation, $operation, $maximum);
    }

    private function reserve(string $uid, string $prefix, string $type, int $maximum): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try { $this->db->commit([$this->prepare($uid, $prefix, $type, $maximum)]); return; }
            catch (HttpException $error) { if ($error->status() !== 409 || $attempt === 2) throw $error; }
        }
    }

    private function prepare(string $uid, string $prefix, string $type, int $maximum, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $window = intdiv($now->getTimestamp(), 3600);
        $path = 'operationQuotas/' . $prefix . '-' . hash('sha256', $uid);
        $record = $this->db->getDocumentVersioned($path);
        $count = ($record['data']['window'] ?? null) === $window ? (int) ($record['data']['count'] ?? 0) : 0;
        if ($count >= $maximum) throw new HttpException(429, 'Demasiadas operaciones. Intentá más tarde.', errorCode: 'RATE_LIMITED');
        $data = ['userId' => $uid, 'tipo' => $type, 'window' => $window, 'count' => $count + 1, 'actualizadoEn' => $now];
        return $record === null ? ['type' => 'create', 'path' => $path, 'data' => $data]
            : ['type' => 'patch', 'path' => $path, 'data' => $data, 'updateTime' => $record['updateTime']];
    }
}
