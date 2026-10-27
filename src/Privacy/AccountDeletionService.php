<?php
declare(strict_types=1);
namespace PawLife\Privacy;

use DateTimeImmutable;
use PawLife\Auth\AuthenticatedUser;
use PawLife\Auth\FirebaseAuthAdmin;
use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;
use PawLife\Notifications\ReminderRateLimiter;
use PawLife\Support\ResourceId;

/** Durable post-order deletion; each checkpoint and its deletions share one commit. */
final class AccountDeletionService
{
    private readonly AccountDeletionStore $store;
    public function __construct(private readonly FirestoreClient $db, private readonly mixed $deleteAuth = null)
    {
        $this->store = $db->deletionStore();
    }
    private static function jobPath(string $uid): string { return 'accountDeletionJobs/' . AccountLifecycle::key($uid); }

    public function start(AuthenticatedUser $user): array
    {
        $user->requireRecentAuthentication(); $uid = $user->uid;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $existing = $this->db->getDocumentVersioned(self::jobPath($uid));
            if ($existing !== null) return self::publicStatus($uid, $existing['data']);
            // Billing does not exist yet. Never erase financial records under a guessed retention policy.
            foreach (['purchases', 'suscripciones'] as $collection) {
                if ($this->db->queryPage($collection, ['userId' => $uid], null, 1)['documents'] !== []) {
                    throw new HttpException(409, 'Contactá a pawlife.support@gmail.com para revisar la suscripción antes de eliminar la cuenta.', errorCode: 'ACCOUNT_BILLING_REVIEW_REQUIRED');
                }
            }
            $lifePath = AccountLifecycle::path($uid); $life = $this->db->getDocumentVersioned($lifePath);
            if ($life !== null && ($life['data']['estado'] ?? null) !== 'activa') throw new HttpException(409, 'La cuenta requiere revisión de soporte.', errorCode: 'ACCOUNT_DELETION_REVIEW_REQUIRED');
            $profile = $this->db->getDocumentVersioned('users/' . $uid);
            $now = new DateTimeImmutable();
            $job = ['userId' => $uid, 'estado' => 'pendiente', 'fase' => 'roots', 'rootIndex' => 0,
                'tasks' => [], 'creadoEn' => $now, 'actualizadoEn' => $now, 'eliminados' => 0, 'pasos' => 0, 'intentos' => 0];
            $lifeData = ['estado' => 'bloqueada', 'revision' => bin2hex(random_bytes(12)), 'actualizadoEn' => $now];
            $ops = [['type' => 'create', 'path' => self::jobPath($uid), 'data' => $job],
                $life === null ? ['type' => 'create', 'path' => $lifePath, 'data' => $lifeData]
                    : ['type' => 'patch', 'path' => $lifePath, 'data' => $lifeData, 'updateTime' => $life['updateTime']]];
            if ($profile !== null) $ops[] = ['type' => 'patch', 'path' => 'users/' . $uid, 'updateTime' => $profile['updateTime'],
                'data' => ['estadoCuenta' => 'eliminando', 'actualizadoEn' => $now]];
            try { $this->store->commit($uid, $ops); return self::publicStatus($uid, $job); }
            catch (HttpException $error) { if ($error->status() !== 409 || $attempt === 4) throw $error; }
        }
        throw new \LogicException('No se pudo reservar el borrado.');
    }

    public function status(string $uid): array
    {
        $job = $this->db->getDocument(self::jobPath($uid));
        return $job === null ? ['estado' => 'sin_solicitud'] : self::publicStatus($uid, $job);
    }
    private static function publicStatus(string $uid, array $job): array
    {
        return ['solicitudId' => AccountLifecycle::key($uid), 'estado' => $job['estado'], 'fase' => $job['fase'],
            'eliminados' => $job['eliminados'] ?? 0, 'creadoEn' => ($job['creadoEn'] ?? null)?->format(DATE_ATOM)];
    }

    /** Worker has no dependency on a surviving Firebase Auth token. */
    public function runPending(int $maximum = 2): array
    {
        $stats = ['procesadas' => 0, 'completadas' => 0, 'pendientes' => 0];
        foreach ($this->db->queryPage('accountDeletionJobs', ['estado' => 'pendiente'], null, min(3, max(1, $maximum)))['documents'] as $job) {
            $uid = $job['data']['userId'] ?? null;
            if (!is_string($uid)) continue;
            try { $result = $this->resume($uid); $stats['procesadas']++; $stats[$result['estado'] === 'completa' ? 'completadas' : 'pendientes']++; }
            catch (\Throwable) { $stats['pendientes']++; }
        }
        return $stats;
    }

    public function resume(string $uid, int $maximumSteps = 12): array
    {
        $path = self::jobPath($uid); $version = $this->db->getDocumentVersioned($path);
        if ($version === null) throw HttpException::notFound('No existe la solicitud.');
        $job = $version['data'];
        if ($job['estado'] === 'completa') return self::publicStatus($uid, $job);
        $now = new DateTimeImmutable();
        if (($job['leaseHasta'] ?? null) instanceof DateTimeImmutable && $job['leaseHasta'] > $now) return self::publicStatus($uid, $job);
        $lease = bin2hex(random_bytes(12));
        $this->store->commit($uid, [['type' => 'patch', 'path' => $path, 'updateTime' => $version['updateTime'],
            'data' => ['lease' => $lease, 'leaseHasta' => $now->modify('+45 seconds'), 'intentos' => ($job['intentos'] ?? 0) + 1]]]);
        $started = microtime(true);
        try {
            for ($i = 0; $i < $maximumSteps && microtime(true) - $started < 20; $i++) {
                $version = $this->db->getDocumentVersioned($path); $job = $version['data'];
                if (($job['lease'] ?? null) !== $lease || !($job['leaseHasta'] ?? null) instanceof DateTimeImmutable || $job['leaseHasta'] <= new DateTimeImmutable()) break;
                [$data, $ops] = $this->step($uid, $job);
                $data += ['actualizadoEn' => new DateTimeImmutable(), 'pasos' => ($job['pasos'] ?? 0) + 1, 'ultimoError' => null];
                $ops[] = ['type' => 'patch', 'path' => $path, 'updateTime' => $version['updateTime'], 'data' => $data];
                $this->store->commit($uid, $ops);
                if (($data['estado'] ?? null) === 'completa') return $this->status($uid);
            }
        } finally {
            $latest = $this->db->getDocumentVersioned($path);
            if ($latest !== null && ($latest['data']['lease'] ?? null) === $lease && $latest['data']['estado'] !== 'completa') {
                $this->store->commit($uid, [['type' => 'patch', 'path' => $path, 'updateTime' => $latest['updateTime'],
                    'data' => ['lease' => null, 'leaseHasta' => new DateTimeImmutable('-1 second')]]]);
            }
        }
        return $this->status($uid);
    }

    private function step(string $uid, array $job): array
    {
        $tasks = $job['tasks'] ?? [];
        if ($tasks !== []) return $this->treeStep($uid, $job, $tasks);
        if ($job['fase'] === 'roots') {
            $roots = ['dispositivos', 'exportSessions', ...array_values(array_diff(AccountLifecycle::OWNED, ['dispositivos', 'exportSessions']))];
            $index = $job['rootIndex'];
            if ($index >= count($roots)) return [['fase' => 'legacy', 'tasks' => [['kind' => 'document', 'path' => 'users/' . $uid, 'expanded' => false]]], []];
            $page = $this->db->queryPage($roots[$index], ['userId' => $uid], null, 1);
            if ($page['documents'] === []) return [['rootIndex' => $index + 1], []];
            return [['tasks' => [['kind' => 'document', 'path' => $roots[$index] . '/' . $page['documents'][0]['id'], 'expanded' => false]]], []];
        }
        if ($job['fase'] === 'legacy') return [['fase' => 'roles'], []];
        if ($job['fase'] === 'roles') {
            $ops = [];
            foreach (['configuracion/panel-notificaciones' => 'administradores', 'configuracion/privacidad' => 'responsables'] as $path => $field) {
                $record = $this->db->getDocumentVersioned($path);
                if ($record !== null && in_array($uid, $record['data'][$field] ?? [], true)) $ops[] = ['type' => 'patch', 'path' => $path, 'updateTime' => $record['updateTime'],
                    'data' => [$field => array_values(array_filter($record['data'][$field], static fn ($v): bool => $v !== $uid))]];
            }
            foreach ([ReminderRateLimiter::documentPath($uid), ReminderRateLimiter::documentPath('admin-notification:' . $uid)] as $path) {
                $record = $this->db->getDocumentVersioned($path);
                if ($record !== null) $ops[] = ['type' => 'delete', 'path' => $path, 'updateTime' => $record['updateTime']];
            }
            return [['fase' => 'actor'], $ops];
        }
        if ($job['fase'] === 'actor') {
            $page = $this->db->queryPage('notificaciones', ['administradorId' => $uid], null, 1);
            if ($page['documents'] === []) return [['fase' => 'verify'], []];
            $path = 'notificaciones/' . $page['documents'][0]['id']; $record = $this->db->getDocumentVersioned($path);
            return [[], [['type' => 'patch', 'path' => $path, 'updateTime' => $record['updateTime'], 'data' => ['administradorId' => null]]]];
        }
        if ($job['fase'] === 'verify') {
            foreach (AccountLifecycle::OWNED as $index => $collection) {
                if ($this->db->queryPage($collection, ['userId' => $uid], null, 1)['documents'] !== []) return [['fase' => 'roots', 'rootIndex' => 0], []];
            }
            $children = $this->collections('users/' . $uid);
            if ($children !== [] || $this->db->getDocument('users/' . $uid) !== null) return [['fase' => 'legacy', 'tasks' => [['kind' => 'document', 'path' => 'users/' . $uid, 'expanded' => false]]], []];
            return [['fase' => 'auth'], []];
        }
        if ($job['fase'] === 'auth') {
            if ($this->deleteAuth !== null) ($this->deleteAuth)($uid); else FirebaseAuthAdmin::deleteUser($uid);
            return [['fase' => 'completa', 'estado' => 'completa', 'userId' => null, 'tasks' => [],
                'lease' => null, 'leaseHasta' => null, 'completadoEn' => new DateTimeImmutable() ], []];
        }
        throw new \LogicException('Fase desconocida de borrado.');
    }

    private function collections(string $path): array
    {
        $ids = []; $token = null; $seen = [];
        do {
            $page = $this->db->exportPage($path, null, $token);
            foreach ($page['collectionIds'] ?? [] as $id) { ResourceId::validate($id); $ids[] = $id; }
            if (count($ids) > 200) throw new HttpException(413, 'El árbol antiguo necesita asistencia de soporte.', errorCode: 'DELETION_TREE_TOO_LARGE');
            $token = $page['nextPageToken'] ?? null;
            if ($token !== null && (!is_string($token) || $token === '' || isset($seen[$token]))) throw new HttpException(502, 'Paginación de borrado inválida.');
            if ($token !== null) $seen[$token] = true;
        } while ($token !== null);
        return array_values(array_unique($ids));
    }

    private function treeStep(string $uid, array $job, array $tasks): array
    {
        $task = array_pop($tasks); $path = $task['path'];
        if (count($tasks) > 220) throw new HttpException(413, 'El árbol necesita asistencia.', errorCode: 'DELETION_TREE_TOO_LARGE');
        if ($task['kind'] === 'collection') {
            $parts = explode('/', $path); $collection = array_pop($parts);
            $page = $this->db->exportPage(implode('/', $parts), $collection, null, 1);
            if (($page['documents'] ?? []) !== []) {
                $name = $page['documents'][0]['name'] ?? ''; $prefix = '/documents/' . $path . '/';
                if (!is_string($name) || !str_contains($name, $prefix)) throw new \LogicException('Documento fuera del árbol.');
                $id = substr($name, strpos($name, $prefix) + strlen($prefix)); ResourceId::validate($id);
                $tasks[] = $task; $tasks[] = ['kind' => 'document', 'path' => $path . '/' . $id, 'expanded' => false];
            }
            return [['tasks' => $tasks], []];
        }
        if (!$task['expanded']) {
            $tasks[] = array_replace($task, ['expanded' => true]);
            foreach ($this->collections($path) as $id) $tasks[] = ['kind' => 'collection', 'path' => $path . '/' . $id];
            return [['tasks' => $tasks], []];
        }
        $record = $this->db->getDocumentVersioned($path);
        $ops = $record === null ? [] : [['type' => 'delete', 'path' => $path, 'updateTime' => $record['updateTime']]];
        return [['tasks' => $tasks, 'eliminados' => ($job['eliminados'] ?? 0) + count($ops)], $ops];
    }
}
