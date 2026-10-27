<?php
declare(strict_types=1);

namespace PawLife\Notifications;

use DateTimeImmutable;
use PawLife\Firestore\AtomicStore;
use PawLife\Http\HttpException;
use PawLife\Resources\Catalog;
use PawLife\Support\ResourceId;

/** Un token ocupa un documento opaco; migración legacy y alta en un solo commit. */
final class DeviceRegistry
{
    public function __construct(private readonly AtomicStore $db) {}

    public static function idFor(string $token): string
    {
        return 'device-' . hash('sha256', $token);
    }

    public function register(string $uid, array $body): array
    {
        ResourceId::validate($uid);
        $data = Catalog::dispositivos()->forCreate($body);
        $token = $data['token'];
        $id = self::idFor($token);
        $path = 'dispositivos/' . $id;
        $profile = $this->profile($uid);
        $existing = $this->db->getDocumentVersioned($path);
        $legacyPath = self::legacyPath($token);
        $legacy = $legacyPath === null || $legacyPath === $path ? null : $this->db->getDocumentVersioned($legacyPath);
        foreach ([$existing, $legacy] as $record) {
            if ($record !== null && ($record['data']['userId'] ?? null) !== $uid) {
                throw new HttpException(409, 'El dispositivo no se puede asociar a esta sesión.', errorCode: 'DEVICE_OWNERSHIP_CONFLICT');
            }
        }
        $now = new DateTimeImmutable();
        $data += ['userId' => $uid, 'activo' => true, 'demo' => false,
            'creadoEn' => $existing['data']['creadoEn'] ?? $legacy['data']['creadoEn'] ?? $now,
            'actualizadoEn' => $now];
        $operations = [self::guardProfile($uid, $profile),
            $existing === null
                ? ['type' => 'create', 'path' => $path, 'data' => $data]
                : ['type' => 'patch', 'path' => $path, 'data' => $data, 'updateTime' => $existing['updateTime']],
        ];
        if ($legacy !== null) $operations[] = ['type' => 'delete', 'path' => $legacyPath, 'updateTime' => $legacy['updateTime']];
        $operations[] = (new \PawLife\Security\WriteQuota($this->db))->prepareDeviceRegistration($uid);
        $this->db->commit($operations);
        return ['id' => $id, 'data' => $data, 'created' => $existing === null];
    }

    public function find(string $uid, string $id): ?array
    {
        ResourceId::validate($id);
        foreach (array_unique(['dispositivos/' . $id, 'dispositivos/' . self::idFor($id)]) as $path) {
            $record = $this->db->getDocumentVersioned($path);
            if ($record === null) continue;
            if (($record['data']['userId'] ?? null) !== $uid) throw HttpException::notFound('No existe el dispositivo.');
            return $record + ['path' => $path, 'id' => substr($path, strlen('dispositivos/'))];
        }
        return null;
    }

    public function update(string $uid, string $id, array $body): array
    {
        $record = $this->find($uid, $id);
        if ($record === null) throw HttpException::notFound('No existe el dispositivo.');
        $changes = Catalog::dispositivos()->forUpdate($body);
        if (isset($changes['token']) && $changes['token'] !== ($record['data']['token'] ?? null)) {
            throw new HttpException(409, 'Registrá el token renovado como un nuevo dispositivo.', errorCode: 'DEVICE_TOKEN_CHANGED');
        }
        return $this->register($uid, array_merge($record['data'], $changes));
    }

    public function remove(string $uid, string $id): void
    {
        $profile = $this->profile($uid);
        $record = $this->find($uid, $id);
        if ($record === null) return;
        $token = $record['data']['token'] ?? null;
        $paths = [$record['path']];
        if (is_string($token) && $token !== '') {
            $paths[] = 'dispositivos/' . self::idFor($token);
            $legacyPath = self::legacyPath($token);
            if ($legacyPath !== null) $paths[] = $legacyPath;
        }
        $operations = [self::guardProfile($uid, $profile)];
        foreach (array_unique($paths) as $path) {
            $versioned = $this->db->getDocumentVersioned($path);
            if ($versioned === null || ($versioned['data']['userId'] ?? null) !== $uid) continue;
            $operations[] = ['type' => 'delete', 'path' => $path, 'updateTime' => $versioned['updateTime']];
        }
        if (count($operations) > 1) $this->db->commit($operations);
    }

    public function revoke(string $uid, array $body): void
    {
        $token = $body['token'] ?? null;
        if (!is_string($token) || trim($token) === '' || strlen($token) > 4096) {
            throw HttpException::badRequest('Falta un token de dispositivo válido.');
        }
        $token = trim($token);
        $this->remove($uid, self::legacyPath($token) === null ? self::idFor($token) : $token);
    }

    private function profile(string $uid): array
    {
        ResourceId::validate($uid);
        $profile = $this->db->getDocumentVersioned('users/' . $uid);
        if ($profile === null) throw HttpException::notFound('No existe el perfil de la sesión.');
        if (($profile['data']['estadoCuenta'] ?? 'activa') !== 'activa') throw HttpException::forbidden('La cuenta no permite registrar dispositivos.');
        return $profile;
    }

    private static function guardProfile(string $uid, array $profile): array
    {
        // REST Write no expone verify. Una escritura condicional del estado ya
        // validado evita que el commit sobreviva a un cambio/borrado del perfil.
        return ['type' => 'patch', 'path' => 'users/' . $uid,
            'data' => ['estadoCuenta' => 'activa'], 'updateTime' => $profile['updateTime']];
    }

    private static function legacyPath(string $token): ?string
    {
        try { ResourceId::validate($token); } catch (HttpException) { return null; }
        return 'dispositivos/' . $token;
    }
}
