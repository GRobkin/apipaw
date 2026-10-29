<?php

declare(strict_types=1);

namespace PawLife\Billing;

use DateTimeImmutable;
use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;
use PawLife\Support\Env;

/**
 * Compra de Premium SIMULADA, de la propia cuenta, mientras no haya proveedor
 * de pago (decisión del 28/10/2026: Premium sin cobro por ahora).
 *
 * Escribe una concesión con origen `prueba`, que `EntitlementService` cuenta
 * como Premium igual que una concesión manual: por eso levanta el tope de
 * mascotas de verdad. No toca concesiones del panel ni suscripciones pagadas.
 *
 * Se apaga con `PREMIUM_TEST_PURCHASES=0` en el entorno del servidor. Hay que
 * apagarla el día que exista un cobro real: mientras esté encendida, cualquier
 * cuenta puede darse Premium sola.
 */
final class TestPurchaseService
{
    public const PLANS = ['mensual' => 30, 'anual' => 365];

    public function __construct(private readonly FirestoreClient $db)
    {
    }

    public static function enabled(): bool
    {
        return Env::get('PREMIUM_TEST_PURCHASES', '1') !== '0';
    }

    private static function path(string $uid): string
    {
        return 'premiumGrants/prueba-' . hash('sha256', $uid);
    }

    /** @return array<string, mixed> */
    public function buy(string $uid, mixed $plan, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        self::assertEnabled();
        if (!is_string($plan) || !isset(self::PLANS[$plan])) {
            throw HttpException::badRequest('Elegí el plan mensual o el anual.');
        }
        $until = $now->modify('+' . self::PLANS[$plan] . ' days');
        $path = self::path($uid);
        $data = [
            'userId' => $uid, 'origen' => 'prueba', 'creadoPor' => $uid, 'creadoEn' => $now,
            'terminaEn' => $until, 'motivo' => 'Compra de prueba (' . $plan . ')',
            'revocadoEn' => null, 'revocadoPor' => null,
        ];
        if ($this->db->getDocument($path) === null) {
            $this->db->createDocument('premiumGrants', $data, basename($path));
        } else {
            $this->db->patchDocument($path, $data);
        }
        return (new EntitlementService($this->db))->selfView($uid, $now);
    }

    /** @return array<string, mixed> */
    public function cancel(string $uid, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        self::assertEnabled();
        $path = self::path($uid);
        if ($this->db->getDocument($path) !== null) {
            $this->db->patchDocument($path, ['revocadoEn' => $now, 'revocadoPor' => $uid]);
        }
        return (new EntitlementService($this->db))->selfView($uid, $now);
    }

    private static function assertEnabled(): void
    {
        if (!self::enabled()) {
            throw new HttpException(403, 'Las compras de prueba están desactivadas.', errorCode: 'TEST_PURCHASES_DISABLED');
        }
    }
}
