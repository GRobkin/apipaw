<?php

declare(strict_types=1);

namespace PawLife\Billing;

use DateTimeImmutable;
use PawLife\Firestore\FirestoreClient;

/**
 * Estado efectivo de Premium de una cuenta, recalculado en cada consulta.
 *
 * Es la unión de fuentes independientes:
 *  - `manual`: concesiones de `premiumGrants` no revocadas y no vencidas.
 *  - `pago`: suscripciones verificadas de `subscriptions` (las escribirá T10; hoy
 *    no hay ninguna, y aquí solo se leen: este servicio nunca crea una compra).
 * Revocar o vencer una fuente no toca a las demás.
 *
 * El vencimiento se comprueba al consultar, no con un cron: si nadie corre
 * ningún proceso, una concesión vencida deja de valer igual.
 *
 * `users.premium` / `users.premiumHasta` son solo una proyección de
 * compatibilidad y NO se tratan como fuente: pueden ser valores heredados sin
 * compra detrás. Se informan en `legacy` para inventariarlos, sin concederlos.
 */
final class EntitlementService
{
    private const ACTIVE_SUBSCRIPTION_STATES = ['activa', 'gracia'];

    public function __construct(private readonly FirestoreClient $db)
    {
    }

    /** @return array<string, mixed> */
    public function effective(string $uid, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $manualUntil = null;
        foreach ($this->db->queryDocuments('premiumGrants', ['userId' => $uid], 'creadoEn desc', 100) as $grant) {
            $until = self::activeGrantEnd($grant['data'], $now);
            if ($until !== null && ($manualUntil === null || $until > $manualUntil)) $manualUntil = $until;
        }
        $paidUntil = null;
        foreach ($this->db->queryDocuments('subscriptions', ['userId' => $uid], 'creadoEn desc', 20) as $subscription) {
            $data = $subscription['data'];
            $end = $data['periodoHasta'] ?? null;
            if (in_array($data['estado'] ?? null, self::ACTIVE_SUBSCRIPTION_STATES, true)
                && $end instanceof DateTimeImmutable && $end > $now && ($paidUntil === null || $end > $paidUntil)) {
                $paidUntil = $end;
            }
        }
        $until = max($manualUntil, $paidUntil);
        $profile = $this->db->getDocument('users/' . $uid) ?? [];
        $legacyUntil = $profile['premiumHasta'] ?? null;

        return [
            'activo' => $until !== null,
            'estado' => $until !== null ? 'activo' : 'inactivo',
            'venceEn' => $until?->format(DATE_ATOM),
            'fuentes' => [
                'manual' => ['activa' => $manualUntil !== null, 'venceEn' => $manualUntil?->format(DATE_ATOM)],
                'pago' => ['activa' => $paidUntil !== null, 'venceEn' => $paidUntil?->format(DATE_ATOM)],
            ],
            'legacy' => [
                'premium' => ($profile['premium'] ?? false) === true,
                'premiumHasta' => $legacyUntil instanceof DateTimeImmutable ? $legacyUntil->format(DATE_ATOM) : null,
            ],
        ];
    }

    /** Fin de la concesión si sigue vigente (no revocada y no vencida); null si no. */
    public static function activeGrantEnd(array $grant, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $end = $grant['terminaEn'] ?? null;
        if (!$end instanceof DateTimeImmutable || ($grant['revocadoEn'] ?? null) instanceof DateTimeImmutable) return null;
        return $end > $now ? $end : null;
    }

    /** Mantiene la proyección de compatibilidad en `users`; no es una fuente de verdad. */
    public function syncProjection(string $uid, ?DateTimeImmutable $now = null): void
    {
        $state = $this->effective($uid, $now);
        $profile = $this->db->getDocument('users/' . $uid);
        if ($profile === null) return;
        $until = $state['venceEn'] !== null ? new DateTimeImmutable($state['venceEn']) : null;
        $this->db->patchDocument('users/' . $uid, ['premium' => $state['activo'], 'premiumHasta' => $until]);
    }
}
