<?php
declare(strict_types=1);

namespace PawLife\Firestore;

use PawLife\Http\HttpException;
use PawLife\Support\ResourceId;

/** Cursor público = ID opaco; los filtros y valores de orden los reconstruye PHP. */
final class QueryPage
{
    public static function build(string $project, string $collection, array $filters, ?string $orderBy,
        int $size, ?string $cursor = null, ?array $after = null): array
    {
        ResourceId::validate($collection);
        if ($size < 1 || $size > 100 || $filters === []) throw HttpException::badRequest('Consulta paginada inválida.');
        $query = ['from' => [['collectionId' => $collection]], 'limit' => $size + 1];
        $clauses = [];
        foreach ($filters as $field => $value) {
            if (!is_string($field) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $field) !== 1) {
                throw HttpException::badRequest('Filtro de consulta inválido.');
            }
            $clauses[] = ['fieldFilter' => ['field' => ['fieldPath' => $field], 'op' => 'EQUAL', 'value' => Value::encode($value)]];
        }
        $query['where'] = count($clauses) === 1 ? $clauses[0] : ['compositeFilter' => ['op' => 'AND', 'filters' => $clauses]];
        $field = '__name__'; $direction = 'ASCENDING';
        if ($orderBy !== null) {
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*) (asc|desc)$/iD', $orderBy, $matches) !== 1) {
                throw HttpException::badRequest('Orden paginado inválido.');
            }
            $field = $matches[1]; $direction = strtolower($matches[2]) === 'desc' ? 'DESCENDING' : 'ASCENDING';
        }
        $query['orderBy'] = [['field' => ['fieldPath' => $field], 'direction' => $direction]];
        if ($field !== '__name__') $query['orderBy'][] = ['field' => ['fieldPath' => '__name__'], 'direction' => $direction];
        if ($cursor !== null) {
            ResourceId::validate($cursor);
            if ($after === null) throw HttpException::badRequest('Cursor no disponible. Actualizá el listado.');
            foreach ($filters as $key => $expected) {
                if (($after[$key] ?? null) !== $expected) throw HttpException::badRequest('Cursor no disponible. Actualizá el listado.');
            }
            if ($field !== '__name__' && !array_key_exists($field, $after)) throw HttpException::badRequest('Cursor no disponible. Actualizá el listado.');
            $reference = ['referenceValue' => AtomicWrite::name($project, "$collection/$cursor")];
            $query['startAt'] = ['values' => $field === '__name__' ? [$reference] : [Value::encode($after[$field]), $reference], 'before' => false];
        }
        return $query;
    }
}
