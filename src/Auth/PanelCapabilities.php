<?php

declare(strict_types=1);

namespace PawLife\Auth;

use PawLife\Billing\ManualGrantService;
use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;

/**
 * Qué puede hacer una cuenta en el panel. Cada endpoint vuelve a comprobar su
 * propia capacidad: esto solo le dice a la interfaz qué módulos mostrar.
 */
final class PanelCapabilities
{
    /** @return array{avisos: bool, premium: bool} */
    public static function resolve(AuthenticatedUser $user, FirestoreClient $db): array
    {
        $avisos = true;
        $premium = true;
        try { NotificationAdmin::assertAllowed($user, $db); } catch (HttpException) { $avisos = false; }
        try { ManualGrantService::assertAllowed($user, $db); } catch (HttpException) { $premium = false; }
        // Sin ninguna capacidad no se entra al panel: mismo 403 de siempre.
        if (!$avisos && !$premium) NotificationAdmin::assertAllowed($user, $db);
        return ['avisos' => $avisos, 'premium' => $premium];
    }
}
