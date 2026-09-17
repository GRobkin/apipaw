<?php

declare(strict_types=1);

namespace PawLife\Firestore;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Traduce entre valores PHP normales y el formato "Value" con el que la API
 * REST de Firestore etiqueta cada dato ({"stringValue": "..."},
 * {"integerValue": "3"}, ...).
 *
 * El SDK oficial hace esto por dentro, pero necesita gRPC y aqui no lo
 * tenemos, asi que la conversion va a mano.
 */
final class Value
{
    /**
     * Un DateTimeInterface se codifica como timestampValue; el resto se deduce
     * del tipo PHP.
     */
    public static function encode(mixed $value): array
    {
        if ($value === null) {
            return ['nullValue' => null];
        }

        if ($value instanceof DateTimeInterface) {
            // Firestore exige RFC3339 en UTC con sufijo Z.
            $utc = DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));

            return ['timestampValue' => $utc->format('Y-m-d\TH:i:s.u\Z')];
        }

        if (is_bool($value)) {
            return ['booleanValue' => $value];
        }

        if (is_int($value)) {
            // integerValue viaja como string: son enteros de 64 bits y JSON no
            // los representa sin perder precision.
            return ['integerValue' => (string) $value];
        }

        if (is_float($value)) {
            return ['doubleValue' => $value];
        }

        if (is_string($value)) {
            return ['stringValue' => $value];
        }

        if (is_array($value)) {
            if (self::isList($value)) {
                return ['arrayValue' => ['values' => array_map([self::class, 'encode'], $value)]];
            }

            $fields = [];
            foreach ($value as $key => $item) {
                $fields[(string) $key] = self::encode($item);
            }

            return ['mapValue' => ['fields' => $fields]];
        }

        throw new InvalidArgumentException('Tipo no soportado por Firestore: ' . get_debug_type($value));
    }

    /**
     * @param array<string, mixed> $value  un objeto Value de la API REST
     */
    public static function decode(array $value): mixed
    {
        if (array_key_exists('nullValue', $value)) {
            return null;
        }

        if (array_key_exists('booleanValue', $value)) {
            return (bool) $value['booleanValue'];
        }

        if (array_key_exists('integerValue', $value)) {
            return (int) $value['integerValue'];
        }

        if (array_key_exists('doubleValue', $value)) {
            return (float) $value['doubleValue'];
        }

        if (array_key_exists('timestampValue', $value)) {
            return new DateTimeImmutable((string) $value['timestampValue']);
        }

        if (array_key_exists('stringValue', $value)) {
            return (string) $value['stringValue'];
        }

        if (array_key_exists('bytesValue', $value)) {
            return (string) $value['bytesValue'];
        }

        if (array_key_exists('referenceValue', $value)) {
            return (string) $value['referenceValue'];
        }

        // Los paseos que guardo la version anterior de la app (cuando Flutter
        // escribia directo en Firestore) tienen la ruta como lista de
        // geoPointValue. Se decodifica para que esos datos se sigan leyendo.
        if (array_key_exists('geoPointValue', $value)) {
            $point = is_array($value['geoPointValue']) ? $value['geoPointValue'] : [];

            return [
                'lat' => (float) ($point['latitude'] ?? 0),
                'lng' => (float) ($point['longitude'] ?? 0),
            ];
        }

        if (array_key_exists('arrayValue', $value)) {
            $values = $value['arrayValue']['values'] ?? [];

            return array_map(
                static fn (array $item) => self::decode($item),
                is_array($values) ? array_values($values) : [],
            );
        }

        if (array_key_exists('mapValue', $value)) {
            return self::decodeFields($value['mapValue']['fields'] ?? []);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $fields  el mapa "fields" de un documento
     *
     * @return array<string, mixed>
     */
    public static function decodeFields(mixed $fields): array
    {
        if (!is_array($fields)) {
            return [];
        }

        $decoded = [];
        foreach ($fields as $name => $value) {
            $decoded[(string) $name] = is_array($value) ? self::decode($value) : null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>  el mapa "fields" listo para la API
     */
    public static function encodeFields(array $data): array
    {
        $fields = [];
        foreach ($data as $name => $value) {
            $fields[(string) $name] = self::encode($value);
        }

        return $fields;
    }

    private static function isList(array $value): bool
    {
        return $value === [] || array_is_list($value);
    }
}
