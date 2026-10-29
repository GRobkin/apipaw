<?php

declare(strict_types=1);

namespace PawLife\Billing;

use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;

/**
 * Límites del plan gratuito. Los impone el servidor: la app solo los muestra.
 * Quien ya tiene más mascotas que el tope conserva las que tiene; lo único que
 * no puede es crear nuevas.
 */
final class PlanLimits
{
    public const FREE_MAX_PETS = 2;

    public static function assertCanAddPet(FirestoreClient $db, string $uid): void
    {
        $owned = count($db->queryDocuments('mascotas', ['userId' => $uid], 'nombre', self::FREE_MAX_PETS + 1));
        if ($owned < self::FREE_MAX_PETS) {
            return;
        }
        if ((new EntitlementService($db))->effective($uid)['activo'] === true) {
            return;
        }
        throw new HttpException(
            402,
            'El plan gratuito permite hasta ' . self::FREE_MAX_PETS . ' mascotas. Pasate a Premium para agregar más.',
            errorCode: 'PLAN_PET_LIMIT',
        );
    }
}
