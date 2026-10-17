<?php

declare(strict_types=1);

namespace PawLife\Auth;

/**
 * El usuario detras de la peticion, sacado del ID token de Firebase.
 *
 * `uid` es lo unico imprescindible: es la raiz del arbol de datos en Firestore
 * (users/{uid}/...). El resto es informativo y viene vacio segun como se haya
 * autenticado (con login anonimo no hay email ni nombre).
 */
final class AuthenticatedUser
{
    public function __construct(
        public readonly string $uid,
        public readonly ?string $email = null,
        public readonly ?string $name = null,
        public readonly ?string $pictureUrl = null,
        public readonly string $signInProvider = 'unknown',
        public readonly ?int $authTime = null,
    ) {
    }

    public function isAnonymous(): bool
    {
        return $this->signInProvider === 'anonymous';
    }

    /** Las operaciones irreversibles requieren haber autenticado hace poco. */
    public function requireRecentAuthentication(?int $now = null): void
    {
        $now ??= time();
        if ($this->authTime === null || $this->authTime > $now + 60
            || $now - $this->authTime > 300) {
            throw \PawLife\Http\HttpException::unauthorized(
                'Por seguridad, cerrá sesión y volvé a ingresar antes de eliminar la cuenta.',
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'email' => $this->email,
            'nombre' => $this->name,
            'fotoUrl' => $this->pictureUrl,
            'proveedor' => $this->signInProvider,
            'anonimo' => $this->isAnonymous(),
        ];
    }
}
