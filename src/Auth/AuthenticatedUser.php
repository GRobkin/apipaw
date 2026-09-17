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
    ) {
    }

    public function isAnonymous(): bool
    {
        return $this->signInProvider === 'anonymous';
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
