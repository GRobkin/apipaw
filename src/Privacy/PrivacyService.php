<?php
declare(strict_types=1);
namespace PawLife\Privacy;

use DateTimeImmutable;
use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;
use PawLife\Security\WriteQuota;
use PawLife\Support\ResourceId;

final class PrivacyService
{
    public const BOOL_PREFERENCE_KEYS = ['recordatoriosCuidados', 'avisosEquipo'];
    public const PREFERENCE_KEYS = ['recordatoriosCuidados', 'avisosEquipo', 'inactividad'];
    public const INACTIVITY_KEYS = ['habilitada', 'intervaloHoras', 'incluirSinPaseos'];
    // Límites técnicos, no clínicos: el intervalo definitivo es decisión de producto.
    public const INACTIVITY_MIN_HOURS = 12;
    public const INACTIVITY_MAX_HOURS = 720;
    public const INACTIVITY_DEFAULT_HOURS = 72;
    public const REQUEST_TYPES = ['acceso', 'correccion', 'oposicion', 'bloqueo', 'anonimizacion', 'consulta'];

    public function __construct(private readonly FirestoreClient $db, private readonly array $contact = []) {}

    public static function preferences(array $profile): array
    {
        $stored = $profile['preferencias'] ?? [];
        if (!is_array($stored)) return ['recordatoriosCuidados' => false, 'avisosEquipo' => false, 'inactividad' => self::inactivity(null)];
        // Preserve existing service notifications; these defaults are not consent to marketing.
        return ['recordatoriosCuidados' => array_key_exists('recordatoriosCuidados', $stored) ? $stored['recordatoriosCuidados'] === true : true,
            'avisosEquipo' => array_key_exists('avisosEquipo', $stored) ? $stored['avisosEquipo'] === true : true,
            'inactividad' => self::inactivity($stored['inactividad'] ?? null)];
    }

    /** Aviso por falta de paseos: apagado por defecto (opt-in). */
    public static function inactivity(mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $hours = $stored['intervaloHoras'] ?? self::INACTIVITY_DEFAULT_HOURS;
        if (!is_int($hours) || $hours < self::INACTIVITY_MIN_HOURS || $hours > self::INACTIVITY_MAX_HOURS) {
            $hours = self::INACTIVITY_DEFAULT_HOURS;
        }
        return ['habilitada' => ($stored['habilitada'] ?? false) === true, 'intervaloHoras' => $hours,
            'incluirSinPaseos' => ($stored['incluirSinPaseos'] ?? false) === true];
    }

    /** @return array<string, mixed> el valor válido de `inactividad` enviado por el cliente */
    private static function validInactivity(mixed $value): array
    {
        if (!is_array($value) || $value === [] || array_is_list($value) || array_diff(array_keys($value), self::INACTIVITY_KEYS) !== []) {
            throw HttpException::badRequest('Preferencia de inactividad inválida.');
        }
        foreach (['habilitada', 'incluirSinPaseos'] as $flag) {
            if (array_key_exists($flag, $value) && !is_bool($value[$flag])) {
                throw HttpException::badRequest('Preferencia de inactividad inválida.');
            }
        }
        if (array_key_exists('intervaloHoras', $value)
            && (!is_int($value['intervaloHoras']) || $value['intervaloHoras'] < self::INACTIVITY_MIN_HOURS
                || $value['intervaloHoras'] > self::INACTIVITY_MAX_HOURS)) {
            throw HttpException::badRequest('El intervalo debe estar entre ' . self::INACTIVITY_MIN_HOURS
                . ' y ' . self::INACTIVITY_MAX_HOURS . ' horas.');
        }
        return $value;
    }

    public function profile(string $uid): array
    {
        ResourceId::validate($uid);
        $profile = $this->db->getDocumentVersioned('users/' . $uid);
        if ($profile === null || ($profile['data']['estadoCuenta'] ?? 'activa') !== 'activa') {
            throw new HttpException(403, 'La cuenta no está disponible.', errorCode: 'ACCOUNT_UNAVAILABLE');
        }
        return $profile;
    }

    public static function guard(string $uid, array $profile): array
    {
        return ['type' => 'patch', 'path' => 'users/' . $uid, 'updateTime' => $profile['updateTime'],
            'data' => ['privacyTouchedAt' => new DateTimeImmutable()]];
    }

    public function info(string $uid): array
    {
        $profile = $this->profile($uid);
        $ready = $this->contactReady();
        return ['schemaVersion' => 1, 'preferencias' => self::preferences($profile['data']),
            'contacto' => $ready ? ['responsable' => $this->contact['responsable'], 'email' => $this->contact['email']] : null,
            'solicitudesHabilitadas' => $ready && ($this->contact['recepcionHabilitada'] ?? false) === true, 'tiposSolicitud' => self::REQUEST_TYPES,
            'politicaPublicada' => false,
            'aviso' => 'El archivo de datos incluye fotos y recorridos GPS. Guardalo en un lugar privado.'];
    }

    private function contactReady(): bool
    {
        return is_string($this->contact['responsable'] ?? null) && trim($this->contact['responsable']) !== ''
            && is_string($this->contact['email'] ?? null) && filter_var($this->contact['email'], FILTER_VALIDATE_EMAIL) !== false;
    }

    public function updatePreferences(string $uid, array $body): array
    {
        if ($body === [] || array_diff(array_keys($body), self::PREFERENCE_KEYS) !== []) throw HttpException::badRequest('Preferencias inválidas.');
        foreach ($body as $key => $value) {
            if ($key === 'inactividad') { $body[$key] = self::validInactivity($value); continue; }
            if (!is_bool($value)) throw HttpException::badRequest('Las preferencias deben ser booleanas.');
        }
        (new WriteQuota($this->db))->reservePrivacy($uid, 'preferences');
        $profile = $this->profile($uid);
        $before = self::preferences($profile['data']);
        $after = $before;
        foreach ($body as $key => $value) {
            $after[$key] = $key === 'inactividad' ? self::inactivity(array_merge($before['inactividad'], $value)) : $value;
        }
        if ($before === $after) return ['preferencias' => $after];
        $changes = [];
        foreach ($body as $key => $value) if ($before[$key] !== $after[$key]) $changes[$key] = $after[$key];
        $now = new DateTimeImmutable();
        $this->db->commit([
            ['type' => 'patch', 'path' => 'users/' . $uid, 'updateTime' => $profile['updateTime'],
                'data' => ['preferencias' => $after, 'actualizadoEn' => $now]],
            ['type' => 'create', 'path' => 'consentEvents/' . bin2hex(random_bytes(16)),
                'data' => ['userId' => $uid, 'tipo' => 'cambio_preferencias', 'schemaVersion' => 1,
                    'cambios' => $changes, 'creadoEn' => $now]],
        ]);
        return ['preferencias' => $after];
    }

    public function request(string $uid, array $body): array
    {
        if (!$this->contactReady()) throw new HttpException(503, 'El canal de privacidad todavía no está configurado.', errorCode: 'PRIVACY_CONTACT_UNAVAILABLE');
        if (($this->contact['recepcionHabilitada'] ?? false) !== true) throw new HttpException(503,
            'Por ahora las solicitudes se atienden por el correo de soporte.', errorCode: 'PRIVACY_REVIEW_UNAVAILABLE');
        if (array_diff(array_keys($body), ['tipo', 'detalle', 'solicitudId']) !== []
            || !in_array($body['tipo'] ?? null, self::REQUEST_TYPES, true)
            || !is_string($body['detalle'] ?? null) || trim($body['detalle']) === '' || strlen($body['detalle']) > 4096
            || !is_string($body['solicitudId'] ?? null) || preg_match('/^[a-zA-Z0-9-]{16,80}$/D', $body['solicitudId']) !== 1) {
            throw HttpException::badRequest('Revisá el tipo y el detalle de la solicitud.');
        }
        $profile = $this->profile($uid);
        $id = hash('sha256', $uid . ':' . $body['solicitudId']);
        $data = ['userId' => $uid, 'tipo' => $body['tipo'], 'detalle' => trim($body['detalle']),
            'estado' => 'recibida', 'creadoEn' => new DateTimeImmutable()];
        $existing = $this->db->getDocument('privacyRequests/' . $id);
        if ($existing !== null) {
            if (($existing['userId'] ?? null) !== $uid || ($existing['tipo'] ?? null) !== $data['tipo'] || ($existing['detalle'] ?? null) !== $data['detalle']) {
                throw new HttpException(409, 'La solicitud ya tiene otro contenido.');
            }
            return $this->requestStatus($uid, $id);
        }
        (new WriteQuota($this->db))->reservePrivacy($uid, 'privacyRequest');
        $this->db->commit([self::guard($uid, $profile), ['type' => 'create', 'path' => 'privacyRequests/' . $id, 'data' => $data]]);
        return ['id' => $id, 'estado' => 'recibida'];
    }

    public function requestStatus(string $uid, string $id): array
    {
        $this->profile($uid); ResourceId::validate($id);
        $record = $this->db->getDocument('privacyRequests/' . $id);
        if ($record === null || ($record['userId'] ?? null) !== $uid) throw HttpException::notFound('No existe la solicitud.');
        return ['id' => $id, 'tipo' => $record['tipo'], 'detalle' => $record['detalle'], 'estado' => $record['estado'],
            'creadoEn' => $record['creadoEn']->format(DATE_ATOM)];
    }

    public function requests(string $uid, ?string $cursor): array
    {
        $this->profile($uid);
        $page = $this->db->queryPage('privacyRequests', ['userId' => $uid], null, 30, $cursor);
        return ['items' => array_map(static fn (array $doc): array => ['id' => $doc['id']] +
            ExportService::project('privacyRequests', $doc['id'], $doc['data']), $page['documents']),
            'nextCursor' => $page['nextCursor']];
    }
}
