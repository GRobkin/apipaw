<?php
declare(strict_types=1);

namespace PawLife\Support;

use PawLife\Http\HttpException;

final class ResourceId
{
    public static function validate(string $id): string
    {
        if ($id === '' || strlen($id) > 1500 || in_array($id, ['.', '..'], true)
            || preg_match('//u', $id) !== 1 || preg_match('/[\/\x00-\x1F\x7F]/u', $id) === 1) {
            throw HttpException::badRequest('Identificador de recurso inválido.');
        }
        return $id;
    }
}
