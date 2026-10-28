<?php

declare(strict_types=1);

namespace PawLife\Billing;

use DateTimeImmutable;
use PawLife\Auth\AuthenticatedUser;
use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;
use PawLife\Notifications\ReminderRateLimiter;
use PawLife\Support\ResourceId;

/**
 * Concesión manual de Premium (sin cobro, sin compra simulada).
 *
 * - Capacidad propia `premium.manage` (configuracion/premium.administradores):
 *   NO se hereda de administrar avisos.
 * - Conceder y revocar exigen autenticación reciente verificada en el servidor.
 * - El vencimiento es obligatorio, futuro y con tope (`duracionMaximaDias` de la
 *   configuración; sin ella, 30 días, un valor prudente hasta que el equipo lo defina).
 * - Cada acción deja una auditoría mínima (actor, cuenta, acción, concesión) en
 *   la misma escritura atómica; ambos documentos pertenecen a la cuenta y se
 *   borran con ella.
 * - Revocar una concesión no toca suscripciones: el estado efectivo lo calcula
 *   EntitlementService a partir de todas las fuentes.
 */
final class ManualGrantService
{
    public const DEFAULT_MAX_DAYS = 30;
    public const MAX_REASON_LENGTH = 140;

    public function __construct(private readonly FirestoreClient $db)
    {
    }

    public static function assertAllowed(AuthenticatedUser $user, FirestoreClient $db): void
    {
        $config = $db->getDocument('configuracion/premium');
        $admins = $config['administradores'] ?? [];
        if (!is_array($admins) || !in_array($user->uid, $admins, true)) {
            throw HttpException::forbidden('Esta cuenta no puede gestionar Premium.');
        }
    }

    public static function maxDays(FirestoreClient $db): int
    {
        $days = $db->getDocument('configuracion/premium')['duracionMaximaDias'] ?? null;
        return is_int($days) && $days >= 1 && $days <= 365 ? $days : self::DEFAULT_MAX_DAYS;
    }

    /** @return array{hasta: DateTimeImmutable, motivo: string, solicitudId: string} */
    public static function validate(array $body, DateTimeImmutable $now, int $maxDays): array
    {
        $until = $body['hasta'] ?? null;
        $reason = $body['motivo'] ?? null;
        $requestId = $body['solicitudId'] ?? null;
        if (array_diff(array_keys($body), ['hasta', 'motivo', 'solicitudId']) !== []) {
            throw HttpException::badRequest('Campos no permitidos.');
        }
        $date = is_string($until) ? DateTimeImmutable::createFromFormat(DATE_ATOM, $until) : false;
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date instanceof DateTimeImmutable || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw HttpException::badRequest('El vencimiento debe ser una fecha con hora (ISO 8601).');
        }
        if ($date <= $now) throw HttpException::badRequest('El vencimiento debe ser futuro.');
        if ($date > $now->modify('+' . $maxDays . ' days')) {
            throw HttpException::badRequest('El vencimiento no puede superar ' . $maxDays . ' días.');
        }
        if (!is_string($reason) || trim($reason) === '' || preg_match('/^.{1,' . self::MAX_REASON_LENGTH . '}$/us', trim($reason)) !== 1) {
            throw HttpException::badRequest('Escribí un motivo breve (hasta ' . self::MAX_REASON_LENGTH . ' caracteres).');
        }
        // El motivo no debe contener datos personales: nada con forma de correo o de número largo.
        if (str_contains($reason, '@') || preg_match('/\d{6,}/', $reason) === 1) {
            throw HttpException::badRequest('El motivo no debe incluir correos ni números de identificación.');
        }
        if (!is_string($requestId) || preg_match('/^[a-zA-Z0-9-]{16,80}$/', $requestId) !== 1) {
            throw HttpException::badRequest('La solicitud necesita un identificador valido.');
        }
        return ['hasta' => $date->setTimezone(new \DateTimeZone('UTC')), 'motivo' => trim($reason), 'solicitudId' => $requestId];
    }

    /** @return array<string, mixed> */
    public function state(AuthenticatedUser $admin, string $uid, ?DateTimeImmutable $now = null): array
    {
        self::assertAllowed($admin, $this->db);
        ResourceId::validate($uid);
        $now ??= new DateTimeImmutable('now');
        $this->requireAccount($uid);
        return $this->view($uid, $now);
    }

    /** @return array<string, mixed> */
    public function grant(AuthenticatedUser $admin, string $uid, array $body, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        self::assertAllowed($admin, $this->db);
        $admin->requireRecentAuthentication($now->getTimestamp());
        ResourceId::validate($uid);
        $data = self::validate($body, $now, self::maxDays($this->db));
        $this->requireAccount($uid);

        $id = 'grant-' . hash('sha256', $admin->uid . ':' . $data['solicitudId']);
        $existing = $this->db->getDocument('premiumGrants/' . $id);
        if ($existing !== null) return $this->replay($existing, $uid, $data, $now);

        (new ReminderRateLimiter($this->db))->consume('premium-admin:' . $admin->uid, $now);
        try {
            $this->db->commit([
                ['type' => 'create', 'path' => 'premiumGrants/' . $id, 'data' => [
                    'userId' => $uid, 'origen' => 'manual', 'creadoPor' => $admin->uid, 'creadoEn' => $now,
                    'terminaEn' => $data['hasta'], 'motivo' => $data['motivo'], 'revocadoEn' => null, 'revocadoPor' => null,
                ]],
                ['type' => 'create', 'path' => 'premiumAudit/audit-' . $id . '-conceder', 'data' => [
                    'userId' => $uid, 'actorId' => $admin->uid, 'accion' => 'conceder', 'grantId' => $id,
                    'terminaEn' => $data['hasta'], 'creadoEn' => $now,
                ]],
            ]);
        } catch (HttpException $e) {
            // Otra pestaña ya la creó con la misma solicitud: se devuelve lo guardado.
            $again = $this->db->getDocument('premiumGrants/' . $id);
            if ($again === null) throw $e;
            return $this->replay($again, $uid, $data, $now);
        }
        $this->project($uid, $now);
        return $this->view($uid, $now) + ['concesionId' => $id];
    }

    /** @return array<string, mixed> */
    public function revoke(AuthenticatedUser $admin, string $uid, string $grantId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        self::assertAllowed($admin, $this->db);
        $admin->requireRecentAuthentication($now->getTimestamp());
        ResourceId::validate($uid);
        if (preg_match('/^grant-[a-f0-9]{64}$/D', $grantId) !== 1) throw HttpException::notFound('No existe la concesión.');
        $record = $this->db->getDocumentVersioned('premiumGrants/' . $grantId);
        if ($record === null || ($record['data']['userId'] ?? null) !== $uid) throw HttpException::notFound('No existe la concesión.');
        if (($record['data']['revocadoEn'] ?? null) instanceof DateTimeImmutable) {
            return $this->view($uid, $now); // intención estable: revocar dos veces no cambia nada
        }
        try {
            $this->db->commit([
                ['type' => 'patch', 'path' => 'premiumGrants/' . $grantId, 'updateTime' => $record['updateTime'],
                    'data' => ['revocadoEn' => $now, 'revocadoPor' => $admin->uid]],
                ['type' => 'create', 'path' => 'premiumAudit/audit-' . $grantId . '-revocar', 'data' => [
                    'userId' => $uid, 'actorId' => $admin->uid, 'accion' => 'revocar', 'grantId' => $grantId, 'creadoEn' => $now,
                ]],
            ]);
        } catch (HttpException $e) {
            $again = $this->db->getDocument('premiumGrants/' . $grantId);
            if (!($again['revocadoEn'] ?? null) instanceof DateTimeImmutable) throw $e;
        }
        $this->project($uid, $now);
        return $this->view($uid, $now);
    }

    private function requireAccount(string $uid): void
    {
        $profile = $this->db->getDocument('users/' . $uid);
        if ($profile === null) throw HttpException::notFound('No existe la cuenta.');
        if (($profile['estadoCuenta'] ?? 'activa') !== 'activa') {
            throw new HttpException(422, 'La cuenta no está activa.', errorCode: 'ACCOUNT_UNAVAILABLE');
        }
    }

    /** @param array<string, mixed> $existing */
    private function replay(array $existing, string $uid, array $data, DateTimeImmutable $now): array
    {
        $end = $existing['terminaEn'] ?? null;
        if (($existing['userId'] ?? null) !== $uid || ($existing['motivo'] ?? null) !== $data['motivo']
            || !$end instanceof DateTimeImmutable || $end->getTimestamp() !== $data['hasta']->getTimestamp()) {
            throw new HttpException(409, 'La solicitud ya se usó con otros datos.');
        }
        return $this->view($uid, $now);
    }

    private function project(string $uid, DateTimeImmutable $now): void
    {
        try {
            (new EntitlementService($this->db))->syncProjection($uid, $now);
        } catch (\Throwable) {
            // La proyección es de compatibilidad: el estado efectivo se recalcula al consultar.
        }
    }

    /** @return array<string, mixed> */
    private function view(string $uid, DateTimeImmutable $now): array
    {
        $grants = [];
        foreach ($this->db->queryDocuments('premiumGrants', ['userId' => $uid], 'creadoEn desc', 20) as $row) {
            $g = $row['data'];
            $grants[] = [
                'id' => $row['id'],
                'estado' => ($g['revocadoEn'] ?? null) instanceof DateTimeImmutable ? 'revocada'
                    : (EntitlementService::activeGrantEnd($g, $now) !== null ? 'activa' : 'vencida'),
                'creadoEn' => ($g['creadoEn'] ?? null) instanceof DateTimeImmutable ? $g['creadoEn']->format(DATE_ATOM) : null,
                'terminaEn' => ($g['terminaEn'] ?? null) instanceof DateTimeImmutable ? $g['terminaEn']->format(DATE_ATOM) : null,
                'revocadoEn' => ($g['revocadoEn'] ?? null) instanceof DateTimeImmutable ? $g['revocadoEn']->format(DATE_ATOM) : null,
                'motivo' => (string) ($g['motivo'] ?? ''),
            ];
        }
        return (new EntitlementService($this->db))->effective($uid, $now) + ['concesiones' => $grants];
    }
}
