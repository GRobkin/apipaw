<?php

declare(strict_types=1);

namespace PawLife\Resources;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use PawLife\Http\HttpException;

/**
 * Conjunto de campos que forman un recurso, con la validacion de entrada y el
 * formateo de salida.
 *
 * La API habla JSON plano: las fechas viajan como cadenas ISO 8601 y no
 * aparece por ningun lado el formato interno de Firestore. Asi el cliente
 * Flutter deja de depender de Timestamp y GeoPoint de cloud_firestore.
 */
final class Schema
{
    /** @param list<Field> $fields */
    public function __construct(
        public readonly string $name,
        public readonly array $fields,
        public readonly ?string $defaultOrderBy = null,
    ) {
    }

    /**
     * Valida el cuerpo de un POST: exige los campos obligatorios y rellena los
     * que tengan valor por defecto.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>  valores PHP listos para Firestore
     */
    public function forCreate(array $body): array
    {
        $errors = [];
        $data = [];

        foreach ($this->fields as $field) {
            $present = array_key_exists($field->name, $body);

            if (!$present || $body[$field->name] === null) {
                if ($field->required) {
                    $errors[$field->name] = 'Es obligatorio.';
                    continue;
                }

                $data[$field->name] = $present ? null : $field->default;
                continue;
            }

            try {
                $data[$field->name] = self::cast($field, $body[$field->name]);
            } catch (ValidationError $e) {
                $errors[$field->name] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw HttpException::badRequest("Datos invalidos para {$this->name}.", $errors);
        }

        return $data;
    }

    /**
     * Valida el cuerpo de un PATCH: solo los campos presentes, y al menos uno.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function forUpdate(array $body): array
    {
        $errors = [];
        $data = [];

        foreach ($this->fields as $field) {
            if (!array_key_exists($field->name, $body)) {
                continue;
            }

            $value = $body[$field->name];

            if ($value === null) {
                if (!$field->nullable) {
                    $errors[$field->name] = 'No puede quedar vacio.';
                    continue;
                }

                $data[$field->name] = null;
                continue;
            }

            try {
                $data[$field->name] = self::cast($field, $value);
            } catch (ValidationError $e) {
                $errors[$field->name] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw HttpException::badRequest("Datos invalidos para {$this->name}.", $errors);
        }

        if ($data === []) {
            $nombres = implode(', ', array_map(static fn (Field $f) => $f->name, $this->fields));
            throw HttpException::badRequest(
                "No se envio ningun campo para actualizar. Campos validos: $nombres.",
            );
        }

        return $data;
    }

    /**
     * Documento de Firestore -> objeto JSON de la API.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function toJson(string $id, array $data): array
    {
        $json = ['id' => $id];

        foreach ($this->fields as $field) {
            $json[$field->name] = self::format($field, $data[$field->name] ?? null);
        }

        // Metadatos que escribe el backend, fuera del esquema del recurso.
        foreach (['creadoEn', 'actualizadoEn'] as $meta) {
            if (isset($data[$meta])) {
                $json[$meta] = self::toIso($data[$meta]);
            }
        }

        return $json;
    }

    private static function cast(Field $field, mixed $value): mixed
    {
        switch ($field->type) {
            case Field::STRING:
                if (!is_string($value) && !is_numeric($value)) {
                    throw new ValidationError('Debe ser texto.');
                }
                $text = trim((string) $value);
                if ($text === '' && $field->required) {
                    throw new ValidationError('No puede estar vacio.');
                }

                return $text;

            case Field::INT:
                if (is_bool($value) || !is_numeric($value)) {
                    throw new ValidationError('Debe ser un numero entero.');
                }

                return (int) $value;

            case Field::DOUBLE:
                if (is_bool($value) || !is_numeric($value)) {
                    throw new ValidationError('Debe ser un numero.');
                }

                return (float) $value;

            case Field::BOOL:
                if (is_bool($value)) {
                    return $value;
                }
                if (in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                    return in_array($value, [1, '1', 'true'], true);
                }

                throw new ValidationError('Debe ser true o false.');

            case Field::TIMESTAMP:
                return self::toDate($value);

            case Field::STRING_LIST:
                if (!is_array($value) || !array_is_list($value)) {
                    throw new ValidationError('Debe ser una lista de textos.');
                }
                foreach ($value as $item) {
                    if (!is_string($item)) {
                        throw new ValidationError('Todos los elementos deben ser texto.');
                    }
                }

                return array_values($value);

            case Field::ROUTE:
                return self::toRoute($value);
        }

        throw new ValidationError("Tipo de campo desconocido: {$field->type}.");
    }

    /**
     * Acepta ISO 8601 ("2026-09-17T14:05:00Z") o milisegundos desde epoch.
     * Lo primero es lo que manda la app; lo segundo va bien para probar con curl.
     */
    private static function toDate(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $millis = (int) $value;
            $date = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $millis / 1000));
            if ($date === false) {
                throw new ValidationError('No es una fecha valida.');
            }

            return $date->setTimezone(new DateTimeZone('UTC'));
        }

        if (!is_string($value) || trim($value) === '') {
            throw new ValidationError('Debe ser una fecha ISO 8601.');
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            throw new ValidationError("No se entiende la fecha \"$value\". Usa ISO 8601.");
        }
    }

    /**
     * @return list<array{lat: float, lng: float, timestamp: DateTimeImmutable|null}>
     */
    private static function toRoute(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ValidationError('Debe ser una lista de puntos {lat, lng, timestamp}.');
        }

        $points = [];
        foreach ($value as $index => $point) {
            if (!is_array($point)) {
                throw new ValidationError("El punto $index no es un objeto.");
            }

            $lat = $point['lat'] ?? $point['latitude'] ?? null;
            $lng = $point['lng'] ?? $point['longitude'] ?? null;

            if (!is_numeric($lat) || !is_numeric($lng)) {
                throw new ValidationError("Al punto $index le faltan lat/lng numericos.");
            }

            $lat = (float) $lat;
            $lng = (float) $lng;

            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                throw new ValidationError("El punto $index esta fuera del rango de coordenadas.");
            }

            $points[] = [
                'lat' => $lat,
                'lng' => $lng,
                // El timestamp por punto es lo que permite reconstruir el ritmo
                // del paseo; si no viene, el punto se guarda igual sin el.
                'timestamp' => isset($point['timestamp']) ? self::toDate($point['timestamp']) : null,
            ];
        }

        return $points;
    }

    private static function format(Field $field, mixed $value): mixed
    {
        if ($value === null) {
            return $field->type === Field::STRING_LIST || $field->type === Field::ROUTE ? [] : null;
        }

        if ($field->type === Field::TIMESTAMP) {
            return self::toIso($value);
        }

        if ($field->type === Field::ROUTE) {
            if (!is_array($value)) {
                return [];
            }

            $points = [];
            foreach ($value as $point) {
                if (!is_array($point)) {
                    continue;
                }

                $points[] = [
                    'lat' => (float) ($point['lat'] ?? 0),
                    'lng' => (float) ($point['lng'] ?? 0),
                    'timestamp' => isset($point['timestamp']) ? self::toIso($point['timestamp']) : null,
                ];
            }

            return $points;
        }

        if ($field->type === Field::STRING_LIST) {
            return is_array($value) ? array_values(array_map('strval', $value)) : [];
        }

        if ($field->type === Field::DOUBLE) {
            return (float) $value;
        }

        if ($field->type === Field::INT) {
            return (int) $value;
        }

        if ($field->type === Field::BOOL) {
            return (bool) $value;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private static function toIso(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.v\Z');
        }

        return is_string($value) ? $value : null;
    }
}
