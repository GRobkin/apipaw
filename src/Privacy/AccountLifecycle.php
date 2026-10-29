<?php
declare(strict_types=1);
namespace PawLife\Privacy;

use DateTimeImmutable;
use PawLife\Firestore\AtomicStore;
use PawLife\Http\HttpException;
use PawLife\Notifications\ReminderRateLimiter;
use PawLife\Support\ResourceId;

/** Every ordinary owned mutation touches this persistent barrier in the same commit. */
final class AccountLifecycle
{
    public const OWNED = ['mascotas', 'vacunas', 'medicamentos', 'alimentaciones', 'pesos', 'visitas', 'paseos',
        'recordatorios', 'dispositivos', 'notificaciones', 'operationQuotas', 'privacyRequests', 'consentEvents', 'exportSessions',
        'premiumGrants', 'premiumAudit'];

    public static function key(string $uid): string { ResourceId::validate($uid); return hash('sha256', $uid); }
    public static function path(string $uid): string { return 'accountLifecycle/' . self::key($uid); }

    public static function guard(AtomicStore $db, array $operations): array
    {
        $owners = [];
        foreach ($operations as $operation) {
            $path = $operation['path']; $parts = explode('/', $path); $data = $operation['data'] ?? [];
            if (in_array($parts[0], ['accountLifecycle', 'accountDeletionJobs'], true)) throw new \LogicException('Operación interna reservada.');
            $old = $operation['type'] === 'create' ? null : $db->getDocumentVersioned($path);
            if ($operation['type'] !== 'create' && $old === null) throw new HttpException(409, 'El documento cambió durante la operación.');
            if ($parts[0] === 'users') {
                $uid = $parts[1];
                if (isset($data['userId']) && $data['userId'] !== $uid) throw HttpException::forbidden('No se puede cambiar el propietario.');
            } elseif (in_array($parts[0], self::OWNED, true)) {
                $uid = $old['data']['userId'] ?? $data['userId'] ?? null;
                if (isset($data['userId']) && $data['userId'] !== $uid) throw HttpException::forbidden('No se puede cambiar el propietario.');
            } elseif ($path === 'system/push-dispatch') {
                continue;
            } elseif ($parts[0] === 'system') {
                $uid = $old['data']['userId'] ?? $data['userId'] ?? null;
                if (!is_string($uid) || !in_array($path, [ReminderRateLimiter::documentPath($uid), ReminderRateLimiter::documentPath('admin-notification:' . $uid)], true)) {
                    throw new \LogicException('Cuota sin ámbito de propietario.');
                }
                if (isset($data['userId']) && $data['userId'] !== $uid) throw HttpException::forbidden('No se puede cambiar el propietario.');
            } else { throw new \LogicException('Escritor no registrado en la barrera.'); }
            if (!is_string($uid) || $uid === '') throw new \LogicException('Recurso sin propietario.');
            $owners[$uid] = true;
        }
        foreach (array_keys($owners) as $uid) {
            $path = self::path($uid); $record = $db->getDocumentVersioned($path);
            $profile = $db->getDocumentVersioned('users/' . $uid);
            if (($record['data']['estado'] ?? 'activa') !== 'activa'
                || ($profile['data']['estadoCuenta'] ?? 'activa') !== 'activa') {
                throw new HttpException(403, 'La cuenta está en proceso de eliminación.', errorCode: 'ACCOUNT_DELETING');
            }
            $data = ['estado' => 'activa', 'revision' => bin2hex(random_bytes(12)), 'actualizadoEn' => new DateTimeImmutable()];
            $operations[] = $record === null ? ['type' => 'create', 'path' => $path, 'data' => $data]
                : ['type' => 'patch', 'path' => $path, 'data' => $data, 'updateTime' => $record['updateTime']];
        }
        return $operations;
    }
}
