<?php

declare(strict_types=1);

namespace PawLife\Resources;

use DateTimeInterface;
use PawLife\Http\HttpException;

final class CareValidator
{
    /** @param array<string, mixed> $data */
    public static function check(string $resource, array $data): void
    {
        $errors = [];
        if ($resource === 'vacunas') {
            if (($data['fechaAplicacion'] ?? null) instanceof DateTimeInterface
                && ($data['proximaFecha'] ?? null) instanceof DateTimeInterface
                && $data['proximaFecha'] < $data['fechaAplicacion']) {
                $errors['proximaFecha'] = 'Debe ser posterior a la aplicacion.';
            }
            if (isset($data['anticipacionDias'])
                && ($data['anticipacionDias'] < 0 || $data['anticipacionDias'] > 365)) {
                $errors['anticipacionDias'] = 'Debe estar entre 0 y 365 dias.';
            }
        }
        if ($resource === 'medicamentos') {
            if (($data['fechaInicio'] ?? null) instanceof DateTimeInterface
                && ($data['fechaFin'] ?? null) instanceof DateTimeInterface
                && $data['fechaFin'] < $data['fechaInicio']) {
                $errors['fechaFin'] = 'Debe ser posterior al inicio.';
            }
            $hours = $data['horarios'] ?? null;
            if (is_array($hours) && ($hours === [] || count(array_filter(
                $hours,
                static fn (mixed $hour): bool => is_string($hour)
                    && preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $hour) === 1,
            )) !== count($hours))) {
                $errors['horarios'] = 'Indicá horarios en formato HH:mm.';
            }
        }
        if ($resource === 'pesos' && isset($data['valorKg']) && $data['valorKg'] <= 0) {
            $errors['valorKg'] = 'Debe ser mayor que cero.';
        }
        if ($resource === 'alimentaciones' && isset($data['cantidadGramos']) && $data['cantidadGramos'] <= 0) {
            $errors['cantidadGramos'] = 'Debe ser mayor que cero.';
        }
        if ($errors !== []) {
            throw HttpException::badRequest('Los datos de cuidado no son coherentes.', $errors);
        }
    }
}
