<?php

declare(strict_types=1);

namespace PawLife\Auth;

use PawLife\Http\HttpException;
use PawLife\Notifications\ReminderStore;

final class NotificationAdmin
{
    public static function assertAllowed(AuthenticatedUser $user, ReminderStore $db): void
    {
        $config = $db->getDocument('configuracion/panel-notificaciones');
        $admins = $config['administradores'] ?? [];
        if (!is_array($admins) || !in_array($user->uid, $admins, true)) {
            throw HttpException::forbidden('Esta cuenta no tiene acceso al panel de notificaciones.');
        }
    }
}
