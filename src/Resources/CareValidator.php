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
        if ($resource === 'paseos') {
            if (array_key_exists('imagenTipo', $data)
                && !in_array($data['imagenTipo'], ['foto', 'mapa', 'desconocida'], true)) {
                $errors['imagenTipo'] = 'Indicá foto, mapa o desconocida.';
            }
            foreach (['duracionSegundos', 'distanciaMetros', 'velocidadMaximaKmh'] as $field) {
                if (isset($data[$field]) && $data[$field] < 0) {
                    $errors[$field] = 'No puede ser negativo.';
                }
            }
            if (($data['fechaInicio'] ?? null) instanceof DateTimeInterface
                && ($data['fechaFin'] ?? null) instanceof DateTimeInterface
                && $data['fechaFin'] < $data['fechaInicio']) {
                $errors['fechaFin'] = 'Debe ser posterior al inicio.';
            }
            $photo = $data['fotoUrl'] ?? null;
            if ($photo !== null && (!is_string($photo) || strlen($photo) > 409600
                || !str_starts_with($photo, 'data:image/jpeg;base64,')
                || base64_decode(substr($photo, 23), true) === false)) {
                $errors['fotoUrl'] = 'Usá una foto JPEG reducida desde la app.';
            }
        }
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
