<?php
declare(strict_types=1);

namespace PawLife\Firestore;

use InvalidArgumentException;
use PawLife\Support\Json;
use PawLife\Support\ResourceId;

final class AtomicWrite
{
    public static function name(string $project, string $path): string
    {
        $parts = explode('/', $path);
        if (count($parts) < 2 || count($parts) % 2 !== 0) {
            throw new InvalidArgumentException('Se requiere una ruta de documento.');
        }
        foreach ($parts as $part) ResourceId::validate($part);
        return 'projects/' . $project . '/databases/(default)/documents/' . $path;
    }

    public static function build(string $project, array $operations): array
    {
        if ($operations === [] || count($operations) > 100) {
            throw new InvalidArgumentException('El commit requiere entre 1 y 100 operaciones.');
        }
        $writes = []; $seen = [];
        foreach ($operations as $operation) {
            $type = $operation['type'] ?? null;
            $path = $operation['path'] ?? null;
            if (!is_string($path) || !in_array($type, ['create', 'patch', 'delete'], true)) {
                throw new InvalidArgumentException('Operación atómica inválida.');
            }
            $name = self::name($project, $path);
            if (isset($seen[$name])) throw new InvalidArgumentException('Documento repetido en el commit.');
            $seen[$name] = true;
            if ($type === 'create') {
                $precondition = ['exists' => false];
            } else {
                $version = $operation['updateTime'] ?? null;
                if (!is_string($version) || preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,9})?Z$/D', $version) !== 1) {
                    throw new InvalidArgumentException('Falta una versión válida para la precondición.');
                }
                $precondition = ['updateTime' => $version];
            }
            $write = ['currentDocument' => $precondition];
            if ($type === 'delete') {
                $write[$type] = $name;
            } else {
                $data = $operation['data'] ?? null;
                if (!is_array($data) || $data === []) throw new InvalidArgumentException('Faltan campos para escribir.');
                foreach (array_keys($data) as $field) {
                    if (!is_string($field) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $field) !== 1) {
                        throw new InvalidArgumentException('Nombre de campo no soportado.');
                    }
                }
                DocumentBudget::assertFits($data);
                $write['update'] = ['name' => $name, 'fields' => Value::encodeFields($data)];
                if ($type === 'patch') $write['updateMask'] = ['fieldPaths' => array_keys($data)];
            }
            $writes[] = $write;
        }
        $body = ['writes' => $writes];
        if (strlen(Json::encode($body)) > 2 * 1024 * 1024) throw new InvalidArgumentException('Commit demasiado grande.');
        return $body;
    }
}
