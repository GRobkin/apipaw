<?php
declare(strict_types=1);
namespace PawLife\Privacy;

use DateTimeImmutable;
use PawLife\Auth\AuthenticatedUser;
use PawLife\Firestore\FirestoreClient;
use PawLife\Firestore\Value;
use PawLife\Http\HttpException;
use PawLife\Resources\Catalog;
use PawLife\Security\WriteQuota;
use PawLife\Support\ResourceId;

/** One authenticated page at a time; sessions retain positions, never exported payloads. */
final class ExportService
{
    public const ROOTS = ['mascotas', 'vacunas', 'medicamentos', 'alimentaciones', 'pesos', 'paseos',
        'recordatorios', 'dispositivos', 'notificaciones', 'privacyRequests', 'consentEvents'];

    public function __construct(private readonly FirestoreClient $db) {}

    public function start(AuthenticatedUser $user): array
    {
        $user->requireRecentAuthentication();
        $privacy = new PrivacyService($this->db);
        $profile = $privacy->profile($user->uid);
        (new WriteQuota($this->db))->reservePrivacy($user->uid, 'exportStart');
        $tasks = [['category' => 'perfil', 'path' => 'users/' . $user->uid, 'kind' => 'profile', 'after' => null]];
        foreach (self::ROOTS as $collection) $tasks[] = ['category' => $collection, 'path' => $collection, 'kind' => 'root', 'after' => null];
        foreach (['mascotas', 'recordatorios', 'dispositivos'] as $collection) {
            $tasks[] = ['category' => $collection, 'path' => "users/{$user->uid}/$collection", 'kind' => 'legacy', 'after' => null];
        }
        $id = bin2hex(random_bytes(16)); $cursor = bin2hex(random_bytes(16));
        $now = new DateTimeImmutable(); $expires = $now->modify('+15 minutes');
        $this->db->commit([PrivacyService::guard($user->uid, $profile), ['type' => 'create', 'path' => 'exportSessions/' . $id,
            'data' => ['userId' => $user->uid, 'authTime' => $user->authTime, 'estado' => 'activa',
                'creadoEn' => $now, 'venceEn' => $expires, 'cursor' => $cursor, 'tasks' => $tasks, 'paginas' => 0]]]);
        return ['id' => $id, 'cursor' => $cursor, 'venceEn' => $expires->format(DATE_ATOM),
            'manifest' => ['schemaVersion' => 1, 'iniciadoEn' => $now->format(DATE_ATOM),
                'categorias' => ['perfil', ...self::ROOTS], 'incluyeLegacy' => true,
                'consistencia' => 'Lectura en vivo por páginas; no es una instantánea transaccional.',
                'exclusiones' => ['Contraseñas, credenciales, tokens FCM y secretos de compra.',
                    'Identidad de administradores y datos de otras personas.',
                    'Logs internos de seguridad, configuración y estados de exportación; acceso complementario por el canal de privacidad.',
                    'Facturación futura y datos de proveedores externos requieren su vía específica de acceso.']]];
    }

    private function session(AuthenticatedUser $user, string $id): array
    {
        ResourceId::validate($id);
        $version = $this->db->getDocumentVersioned('exportSessions/' . $id);
        $data = $version['data'] ?? [];
        if ($version === null || ($data['userId'] ?? null) !== $user->uid) throw HttpException::notFound('No existe la exportación.');
        if (!($data['venceEn'] ?? null) instanceof DateTimeImmutable || $data['venceEn'] <= new DateTimeImmutable()) {
            throw new HttpException(410, 'La exportación venció. Iniciá otra.', errorCode: 'EXPORT_EXPIRED');
        }
        if (($data['estado'] ?? null) === 'cancelada') throw new HttpException(410, 'La exportación fue cancelada.', errorCode: 'EXPORT_CANCELLED');
        if (($user->authTime ?? 0) < ($data['authTime'] ?? PHP_INT_MAX)) throw HttpException::unauthorized('Confirmá tu identidad para acceder a esta exportación.');
        return $version;
    }

    public function page(AuthenticatedUser $user, string $id, ?string $cursor): array
    {
        $profile = (new PrivacyService($this->db))->profile($user->uid);
        $version = $this->session($user, $id); $session = $version['data'];
        if (!is_string($cursor) || preg_match('/^[a-f0-9]{32}$/D', $cursor) !== 1) throw HttpException::badRequest('Cursor de exportación inválido.');
        $replay = hash_equals((string) ($session['previousCursor'] ?? ''), $cursor);
        if (!$replay && !hash_equals((string) ($session['cursor'] ?? ''), $cursor)) throw HttpException::badRequest('Cursor de exportación inválido.');
        (new WriteQuota($this->db))->reservePrivacy($user->uid, 'exportPage');
        $task = $replay ? $session['previousTask'] : ($session['tasks'][0] ?? null);
        if (!is_array($task)) throw new HttpException(409, 'La exportación ya terminó.');
        [$items, $nextTask, $children] = $this->readTask($user, $task, $profile['data']);
        if (strlen(\PawLife\Support\Json::encode($items)) > 1800000) {
            throw new HttpException(413, 'Un registro antiguo requiere asistencia para exportarlo por su tamaño.', errorCode: 'EXPORT_RECORD_TOO_LARGE');
        }
        if ($replay) {
            // A repeated page must not bypass cancellation/account changes during the read.
            $this->db->commit([PrivacyService::guard($user->uid, $profile),
                ['type' => 'patch', 'path' => 'exportSessions/' . $id, 'updateTime' => $version['updateTime'],
                    'data' => ['ultimoAccesoEn' => new DateTimeImmutable()]]]);
            return ['items' => $items, 'nextCursor' => $session['cursor'], 'finalizadoEn' => $session['finalizadoEn'] ?? null];
        }
        if (($session['paginas'] ?? 0) >= 3000) throw new HttpException(413, 'La exportación requiere asistencia por su tamaño.', errorCode: 'EXPORT_TOO_LARGE');
        $tasks = array_slice($session['tasks'], 1);
        if ($nextTask !== null) array_unshift($tasks, $nextTask);
        $tasks = [...$children, ...$tasks];
        $next = $tasks === [] ? null : bin2hex(random_bytes(16));
        $finished = $next === null ? (new DateTimeImmutable())->format(DATE_ATOM) : null;
        $this->db->commit([PrivacyService::guard($user->uid, $profile),
            ['type' => 'patch', 'path' => 'exportSessions/' . $id, 'updateTime' => $version['updateTime'],
                'data' => ['tasks' => $tasks, 'cursor' => $next, 'previousCursor' => $cursor, 'previousTask' => $task,
                    'paginas' => $session['paginas'] + 1, 'estado' => $next === null ? 'completa' : 'activa', 'finalizadoEn' => $finished]]]);
        return ['items' => $items, 'nextCursor' => $next, 'finalizadoEn' => $finished];
    }

    private function readTask(AuthenticatedUser $user, array $task, array $profile): array
    {
        if ($task['kind'] === 'profile') {
            $record = Catalog::perfil()->toJson($user->uid, $profile);
            $record['email'] = $user->email;
            $record['preferencias'] = PrivacyService::preferences($profile);
            return [[['categoria' => 'perfil', 'origen' => 'actual', 'datos' => $record]], null, []];
        }
        $children = [];
        if ($task['kind'] === 'root') {
            $page = $this->db->queryPage($task['path'], ['userId' => $user->uid], null, 1, $task['after']);
            $docs = $page['documents']; $after = $page['nextCursor'];
        } else {
            $segments = explode('/', $task['path']); $collection = array_pop($segments);
            if (!str_starts_with($task['path'], 'users/' . $user->uid . '/')) throw new \LogicException('Ámbito legacy inválido.');
            $page = $this->db->exportPage(implode('/', $segments), $collection, $task['after'], 1);
            $docs = [];
            foreach ($page['documents'] ?? [] as $raw) {
                $name = $raw['name'] ?? ''; $expected = '/documents/' . $task['path'] . '/';
                if (!is_string($name) || !str_contains($name, $expected)) throw new HttpException(502, 'Respuesta legacy inválida.');
                $docId = substr($name, strpos($name, $expected) + strlen($expected)); ResourceId::validate($docId);
                $data = Value::decodeFields($raw['fields'] ?? []);
                if (isset($data['userId']) && $data['userId'] !== $user->uid) throw new HttpException(409, 'Hay datos antiguos que necesitan revisión.', errorCode: 'EXPORT_DATA_CONFLICT');
                if (isset($raw['createTime'])) $docs[] = ['id' => $docId, 'data' => $data];
                if ($task['category'] === 'mascotas') {
                    foreach (['vacunas', 'medicamentos', 'alimentaciones', 'pesos', 'paseos'] as $child) {
                        $children[] = ['category' => $child, 'path' => $task['path'] . '/' . $docId . '/' . $child, 'kind' => 'legacy', 'after' => null];
                    }
                }
            }
            $after = $page['nextPageToken'] ?? null;
        }
        $items = [];
        foreach ($docs as $doc) $items[] = ['categoria' => $task['category'], 'origen' => $task['kind'] === 'root' ? 'actual' : 'legacy',
            'datos' => self::project($task['category'], $doc['id'], $doc['data'])];
        $nextTask = $after === null ? null : array_replace($task, ['after' => $after]);
        return [$items, $nextTask, $children];
    }

    public static function project(string $category, string $id, array $data): array
    {
        if (in_array($category, ['mascotas', 'vacunas', 'medicamentos', 'alimentaciones', 'pesos', 'paseos', 'recordatorios'], true)) {
            return Catalog::{$category}()->toJson($id, $data);
        }
        $allowed = match ($category) {
            'dispositivos' => ['plataforma', 'activo', 'creadoEn', 'actualizadoEn'],
            'notificaciones' => ['tipo', 'mensaje', 'estado', 'recordatorioId', 'creadoEn', 'actualizadoEn', 'enviadaEn'],
            'privacyRequests' => ['tipo', 'detalle', 'estado', 'creadoEn', 'actualizadoEn'],
            'consentEvents' => ['tipo', 'cambios', 'schemaVersion', 'creadoEn'],
            default => throw new \LogicException('Categoría no exportable.'),
        };
        $result = ['id' => $category === 'dispositivos' ? 'installation-' . hash('sha256', $id) : $id];
        foreach ($allowed as $field) if (array_key_exists($field, $data)) $result[$field] = self::jsonValue($data[$field]);
        return $result;
    }

    private static function jsonValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) return $value->format(DATE_ATOM);
        if (is_array($value)) return array_map(self::jsonValue(...), $value);
        return $value;
    }

    public function cancel(AuthenticatedUser $user, string $id): void
    {
        $profile = (new PrivacyService($this->db))->profile($user->uid);
        $session = $this->session($user, $id);
        $this->db->commit([PrivacyService::guard($user->uid, $profile),
            ['type' => 'patch', 'path' => 'exportSessions/' . $id, 'updateTime' => $session['updateTime'],
                'data' => ['estado' => 'cancelada', 'tasks' => [], 'previousTask' => null, 'cursor' => null]]]);
    }
}
