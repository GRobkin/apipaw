<?php
declare(strict_types=1);
namespace PawLife\Privacy;

use DateTimeImmutable;
use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;
use PawLife\Notifications\ReminderRateLimiter;

/** Internal worker capability, deliberately not a general "skip authorization" switch. */
final class AccountDeletionStore
{
    public function __construct(private readonly FirestoreClient $db, private readonly mixed $write) {}

    public function commit(string $uid, array $operations): array
    {
        $lifePath = AccountLifecycle::path($uid); $jobPath = 'accountDeletionJobs/' . AccountLifecycle::key($uid);
        $hasLife = false;
        foreach ($operations as $op) {
            $path = $op['path']; $type = $op['type']; $data = $op['data'] ?? []; $parts = explode('/', $path);
            if ($path === $lifePath) {
                if (!in_array($type, ['create', 'patch'], true) || ($data['estado'] ?? null) !== 'bloqueada') throw new \LogicException('Barrera inválida.');
                $hasLife = true; continue;
            }
            if ($path === $jobPath) {
                if (!in_array($type, ['create', 'patch'], true)
                    || (isset($data['userId']) && $data['userId'] !== $uid)
                    || (array_key_exists('userId', $data) && $data['userId'] === null && ($data['estado'] ?? null) !== 'completa')) {
                    throw new \LogicException('Job fuera de ámbito.');
                }
                continue;
            }
            $existing = $this->db->getDocumentVersioned($path);
            if ($existing === null) throw new HttpException(409, 'El registro cambió durante el borrado.');
            if ($parts[0] === 'users' && ($parts[1] ?? null) === $uid) {
                if (isset($existing['data']['userId']) && $existing['data']['userId'] !== $uid) throw HttpException::forbidden('Registro antiguo con propietario contradictorio.');
                if ($type === 'delete') continue;
                if (count($parts) === 2 && $type === 'patch' && ($data['estadoCuenta'] ?? null) === 'eliminando'
                    && array_diff(array_keys($data), ['estadoCuenta', 'actualizadoEn']) === []) continue;
            }
            if ($path === 'configuracion/panel-notificaciones' || $path === 'configuracion/privacidad') {
                $field = $path === 'configuracion/panel-notificaciones' ? 'administradores' : 'responsables';
                $before = $existing['data'][$field] ?? [];
                $after = array_values(array_filter($before, static fn ($value): bool => $value !== $uid));
                if ($type === 'patch' && $data === [$field => $after]) continue;
                throw new \LogicException('Solo se permite retirar el propio rol.');
            }
            if ($parts[0] === 'notificaciones' && count($parts) === 2 && $type === 'patch'
                && ($existing['data']['administradorId'] ?? null) === $uid && $data === ['administradorId' => null]) continue;
            if ($type === 'delete' && in_array($path, [ReminderRateLimiter::documentPath($uid), ReminderRateLimiter::documentPath('admin-notification:' . $uid)], true)) continue;
            if ($type === 'delete' && in_array($parts[0], AccountLifecycle::OWNED, true)) {
                $root = count($parts) === 2 ? $existing : $this->db->getDocumentVersioned($parts[0] . '/' . $parts[1]);
                if (($root['data']['userId'] ?? null) === $uid
                    && (!isset($existing['data']['userId']) || $existing['data']['userId'] === $uid)) continue;
            }
            throw HttpException::forbidden('El borrado intentó salir de su cuenta.');
        }
        if (!$hasLife) {
            $life = $this->db->getDocumentVersioned($lifePath);
            if (($life['data']['estado'] ?? null) !== 'bloqueada') throw new \LogicException('Falta barrera de borrado.');
            $operations[] = ['type' => 'patch', 'path' => $lifePath, 'updateTime' => $life['updateTime'],
                'data' => ['estado' => 'bloqueada', 'revision' => bin2hex(random_bytes(12)), 'actualizadoEn' => new DateTimeImmutable()]];
        }
        return ($this->write)($operations);
    }
}
