<?php
declare(strict_types=1);
namespace PawLife\Auth;

use DateTimeImmutable;
use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;

/**
 * El correo del perfil solo cambia desde un ID token cuyo correo Firebase ya verificó
 * (verifyBeforeUpdateEmail): el cliente jamás puede escribirlo ni se cambia por texto libre.
 */
final class EmailSync
{
    /** @param array<string, mixed> $profile datos actuales de users/{uid} */
    public static function sync(FirestoreClient $db, AuthenticatedUser $user, array $profile): array
    {
        $email = $user->email;
        if ($email === null || $email === '' || !$user->emailVerified || $user->isAnonymous()) return $profile;
        if (($profile['estadoCuenta'] ?? 'activa') !== 'activa') return $profile;
        $stored = $profile['email'] ?? null;
        if (is_string($stored) && strcasecmp($stored, $email) === 0) return $profile;
        try {
            $updated = $db->patchDocument('users/' . $user->uid, ['email' => $email, 'actualizadoEn' => new DateTimeImmutable()]);
            return $updated['data'];
        } catch (HttpException $error) {
            // Cuenta en borrado o escritura concurrente: se reintenta en la próxima petición.
            if (in_array($error->status(), [403, 409], true)) return $profile;
            throw $error;
        }
    }
}
