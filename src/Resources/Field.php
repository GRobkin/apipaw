<?php

declare(strict_types=1);

namespace PawLife\Resources;

/**
 * Definicion de un campo de un recurso: como se valida lo que llega del
 * cliente y como se devuelve.
 *
 * Los seis recursos de PawLife (mascotas, vacunas, medicamentos, pesos,
 * paseos y recordatorios) son el mismo CRUD con distintos campos, asi que en
 * vez de seis controladores casi identicos se describe cada uno con una lista
 * de Field y un unico controlador generico los sirve todos.
 */
final class Field
{
    public const STRING = 'string';
    public const INT = 'int';
    public const DOUBLE = 'double';
    public const BOOL = 'bool';
    public const TIMESTAMP = 'timestamp';
    public const STRING_LIST = 'string_list';

    /** Lista de puntos GPS: [{lat, lng, timestamp}, ...]. Solo la usa "paseos". */
    public const ROUTE = 'route';

    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $required = false,
        public readonly mixed $default = null,
        public readonly bool $nullable = false,
    ) {
    }

    public static function string(string $name, bool $required = false, bool $nullable = true): self
    {
        return new self($name, self::STRING, $required, null, $nullable);
    }

    public static function int(string $name, bool $required = false, ?int $default = null): self
    {
        return new self($name, self::INT, $required, $default, $default === null);
    }

    public static function double(string $name, bool $required = false): self
    {
        return new self($name, self::DOUBLE, $required, null, !$required);
    }

    public static function bool(string $name, bool $default = false): self
    {
        return new self($name, self::BOOL, false, $default, false);
    }

    public static function timestamp(string $name, bool $required = false, bool $nullable = true): self
    {
        return new self($name, self::TIMESTAMP, $required, null, $nullable);
    }

    public static function stringList(string $name, bool $required = false): self
    {
        return new self($name, self::STRING_LIST, $required, [], false);
    }

    public static function route(string $name, bool $required = false): self
    {
        return new self($name, self::ROUTE, $required, [], false);
    }
}
