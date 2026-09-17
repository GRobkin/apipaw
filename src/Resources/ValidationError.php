<?php

declare(strict_types=1);

namespace PawLife\Resources;

use RuntimeException;

/**
 * Fallo de validacion de UN campo. Schema los va juntando para poder
 * devolverle al cliente todos los errores de una vez, en vez de obligarle a
 * descubrirlos de uno en uno.
 */
final class ValidationError extends RuntimeException
{
}
