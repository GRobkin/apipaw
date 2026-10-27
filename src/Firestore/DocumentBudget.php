<?php
declare(strict_types=1);

namespace PawLife\Firestore;

use PawLife\Http\HttpException;
use PawLife\Support\Json;

/** Presupuesto conservador: incluye el overhead JSON REST, no solo el texto. */
final class DocumentBudget
{
    public const MAX_ENCODED_BYTES = 900 * 1024;
    public const MAX_ROUTE_POINTS = 6000;

    public static function assertFits(array $data): void
    {
        if (strlen(Json::encode(['fields' => Value::encodeFields($data)])) > self::MAX_ENCODED_BYTES) {
            throw new HttpException(413,
                'El registro supera el tamaño permitido. No se guardó una versión incompleta.',
                errorCode: 'DOCUMENT_TOO_LARGE');
        }
    }
}
