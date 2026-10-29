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
    public const FREE_MAX_PENDING_TASKS = 5;

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

    /** Tareas sin completar creadas a mano. Las completadas liberan cupo. */
    public static function assertCanAddTask(FirestoreClient $db, string $uid): void
    {
        $pending = 0;
        foreach ($db->queryDocuments('recordatorios', ['userId' => $uid, 'completado' => false], 'fecha', 100) as $doc) {
            if (($doc['data']['tipo'] ?? null) === 'tarea') $pending++;
        }
        if ($pending < self::FREE_MAX_PENDING_TASKS) {
            return;
        }
        if ((new EntitlementService($db))->effective($uid)['activo'] === true) {
            return;
        }
        throw new HttpException(
            402,
            'El plan gratuito permite hasta ' . self::FREE_MAX_PENDING_TASKS . ' tareas pendientes. Completá alguna o pasate a Premium.',
            errorCode: 'PLAN_TASK_LIMIT',
        );
    }
}
