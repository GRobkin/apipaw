<?php

declare(strict_types=1);

namespace PawLife\Firestore;

use PawLife\Auth\ServiceAccount;
use PawLife\Http\HttpException;
use PawLife\Notifications\ReminderStore;
use PawLife\Support\Env;
use PawLife\Support\HttpClient;
use PawLife\Support\Json;

/**
 * Cliente de la API REST de Firestore.
 *
 * Por que REST y no el SDK: google/cloud-firestore habla gRPC y necesita la
 * extension de PHP correspondiente, que el runtime de Vercel no incluye y no
 * se puede instalar. La API REST expone las mismas operaciones sobre HTTP
 * normal, que es lo unico que hay disponible aqui.
 *
 * Las peticiones van firmadas con la service account, que se salta las reglas
 * de firestore.rules. Por eso el aislamiento entre usuarios NO depende de las
 * reglas sino de que cada consulta de colecciones raíz filtra por userId,
 * obtenido del ID token verificado (ver Auth\FirebaseTokenVerifier).
 */
final class FirestoreClient implements ReminderStore, AtomicStore
{
    private const BASE = 'https://firestore.googleapis.com/v1';

    /** Tope de paginas al listar, para que una coleccion enorme no agote la funcion. */
    private const MAX_PAGES = 10;

    public function __construct(
        private readonly string $projectId,
        private readonly ?AccessTokenProvider $tokens,
        private readonly mixed $transport = null,
    ) {
        if ($transport !== null && !is_callable($transport)) throw new \InvalidArgumentException('Transporte inválido.');
        if ($transport === null && $tokens === null) throw new \InvalidArgumentException('Falta autenticación de Firestore.');
    }

    public function commit(array $operations): array
    {
        AtomicWrite::build($this->projectId, $operations);
        return $this->rawCommit(\PawLife\Privacy\AccountLifecycle::guard($this, $operations));
    }

    private function rawCommit(array $operations): array
    {
        $result = $this->request('POST', rtrim($this->documentsUrl(''), '/') . ':commit',
            AtomicWrite::build($this->projectId, $operations)) ?? [];
        if (!isset($result['writeResults'], $result['commitTime']) || !is_array($result['writeResults'])
            || count($result['writeResults']) !== count($operations) || !is_string($result['commitTime'])) {
            throw new HttpException(502, 'No se pudo confirmar el resultado de la operación.', errorCode: 'COMMIT_RESULT_UNKNOWN');
        }
        return $result;
    }

    /** Internal capability: its validator permits only this account's deletion plan. */
    public function deletionStore(): \PawLife\Privacy\AccountDeletionStore
    {
        return new \PawLife\Privacy\AccountDeletionStore($this, fn (array $operations): array => $this->rawCommit($operations));
    }

    public function queryPage(string $collection, array $filters, ?string $orderBy = null, int $size = 30, ?string $cursor = null): array
    {
        \PawLife\Support\ResourceId::validate($collection);
        if ($cursor !== null) \PawLife\Support\ResourceId::validate($cursor);
        $after = $cursor === null ? null : $this->getDocument("$collection/$cursor");
        $query = QueryPage::build($this->projectId, $collection, $filters, $orderBy, $size, $cursor, $after);
        $rows = $this->request('POST', rtrim($this->documentsUrl(''), '/') . ':runQuery', ['structuredQuery' => $query]) ?? [];
        $documents = [];
        foreach ($rows as $row) {
            if (!isset($row['document'])) continue;
            $doc = $row['document'];
            $documents[] = ['id' => self::idFromName($doc['name']), 'data' => Value::decodeFields($doc['fields'] ?? [])];
        }
        $more = count($documents) > $size;
        $documents = array_slice($documents, 0, $size);
        return ['documents' => $documents, 'nextCursor' => $more ? $documents[array_key_last($documents)]['id'] : null];
    }

    public static function fromEnv(): self
    {
        $serviceAccount = ServiceAccount::fromEnv();

        return new self(
            Env::get('FIREBASE_PROJECT_ID', $serviceAccount->projectId) ?? $serviceAccount->projectId,
            new AccessTokenProvider($serviceAccount),
        );
    }

    /** CLI backup only: keep raw REST types and enumerate even missing parents. */
    public function exportPage(string $parent, ?string $collection, ?string $token, int $size = 20): array
    {
        if ($size < 1 || $size > 20) throw new \InvalidArgumentException('Página de exportación inválida.');
        if ($parent !== '') {
            $segments = explode('/', $parent);
            if (count($segments) % 2 !== 0) throw new \InvalidArgumentException('Padre de exportación inválido.');
            foreach ($segments as $segment) \PawLife\Support\ResourceId::validate($segment);
        }
        if ($collection !== null) \PawLife\Support\ResourceId::validate($collection);
        if ($token !== null && (strlen($token) > 8192 || $token === '')) throw new \InvalidArgumentException('Token inválido.');
        if ($collection === null) {
            $result = $this->request('POST', rtrim($this->documentsUrl($parent), '/') . ':listCollectionIds',
                ['pageSize' => 100] + ($token === null ? [] : ['pageToken' => $token])) ?? [];
            $field = 'collectionIds';
        } else {
            $result = $this->request('GET', $this->documentsUrl(ltrim("$parent/$collection", '/'),
                ['pageSize' => (string) $size, 'showMissing' => 'true'] + ($token === null ? [] : ['pageToken' => $token]))) ?? [];
            $field = 'documents';
        }
        if (array_diff(array_keys($result), [$field, 'nextPageToken']) !== []
            || (isset($result[$field]) && (!is_array($result[$field]) || !array_is_list($result[$field])))) {
            throw new HttpException(502, 'Respuesta de exportación inválida.', errorCode: 'DEPENDENCY_RESPONSE_INVALID');
        }
        return $result;
    }

    /** Página estable de paseos de una mascota; el cursor jamás cambia el dueño. */
    public function walkHistoryPage(string $uid, string $petId, ?string $cursor, int $limit = 30): array
    {
        $pet = $this->getDocument('mascotas/' . $petId);
        if ($pet === null || ($pet['userId'] ?? null) !== $uid) {
            throw HttpException::notFound('No existe la mascota.');
        }
        $after = null;
        if ($cursor !== null) {
            if (preg_match('/^[^\x00-\x1F\/]{1,1500}$/u', $cursor) !== 1 || in_array($cursor, ['.', '..'], true)) {
                throw HttpException::badRequest('Cursor de paseo invalido.');
            }
            $after = $this->getDocument('paseos/' . $cursor);
            if ($after === null || ($after['userId'] ?? null) !== $uid || ($after['mascotaId'] ?? null) !== $petId) {
                throw HttpException::badRequest('El paseo del cursor ya no esta disponible. Actualizá el historial.');
            }
        }
        $query = self::walkHistoryQuery($uid, $petId, $limit + 1);
        if ($after !== null) {
            $query['startAt'] = [
                'values' => [Value::encode($after['fechaInicio']), ['referenceValue' => 'projects/' . $this->projectId . '/databases/(default)/documents/paseos/' . $cursor]],
                'before' => false,
            ];
        }
        $response = $this->request('POST', rtrim($this->documentsUrl(''), '/') . ':runQuery', ['structuredQuery' => $query]);
        $documents = [];
        foreach ($response ?? [] as $row) {
            if (!isset($row['document'])) continue;
            $doc = $row['document'];
            $documents[] = ['id' => self::idFromName($doc['name']), 'data' => Value::decodeFields($doc['fields'] ?? [])];
        }
        $more = count($documents) > $limit;
        $documents = array_slice($documents, 0, $limit);
        return ['documents' => $documents, 'nextCursor' => $more ? $documents[array_key_last($documents)]['id'] : null];
    }

    public static function walkHistoryQuery(string $uid, string $petId, int $limit): array
    {
        return [
            'from' => [['collectionId' => 'paseos']],
            'where' => ['compositeFilter' => ['op' => 'AND', 'filters' => [
                ['fieldFilter' => ['field' => ['fieldPath' => 'userId'], 'op' => 'EQUAL', 'value' => Value::encode($uid)]],
                ['fieldFilter' => ['field' => ['fieldPath' => 'mascotaId'], 'op' => 'EQUAL', 'value' => Value::encode($petId)]],
            ]]],
            'orderBy' => [
                ['field' => ['fieldPath' => 'fechaInicio'], 'direction' => 'DESCENDING'],
                ['field' => ['fieldPath' => '__name__'], 'direction' => 'DESCENDING'],
            ],
            'limit' => max(1, min(51, $limit)),
        ];
    }

    /**
     * Documentos de una coleccion, ya decodificados.
     *
     * @param string      $collectionPath  ej. "users/abc/mascotas"
     * @param string|null $orderBy         ej. "fechaInicio desc"
     *
     * @return list<array{id: string, data: array<string, mixed>}>
     */
    public function listDocuments(string $collectionPath, ?string $orderBy = null, ?int $limit = null): array
    {
        $documents = [];
        $pageToken = null;
        $pages = 0;

        do {
            $query = ['pageSize' => (string) min(300, $limit ?? 300)];
            if ($orderBy !== null) {
                $query['orderBy'] = $orderBy;
            }
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            $response = $this->request('GET', $this->documentsUrl($collectionPath, $query));

            foreach ($response['documents'] ?? [] as $document) {
                if (!is_array($document)) {
                    continue;
                }

                $documents[] = [
                    'id' => self::idFromName((string) ($document['name'] ?? '')),
                    'data' => Value::decodeFields($document['fields'] ?? []),
                ];

                if ($limit !== null && count($documents) >= $limit) {
                    return $documents;
                }
            }

            $pageToken = isset($response['nextPageToken']) ? (string) $response['nextPageToken'] : null;
            $pages++;
        } while ($pageToken !== null && $pages < self::MAX_PAGES);

        if ($pageToken !== null) {
            throw new HttpException(503, 'La coleccion supera el limite de lectura de esta operacion.');
        }

        return $documents;
    }

    /**
     * Consulta una colección raíz por campos de igualdad.
     *
     * @param array<string, mixed> $filters
     * @return list<array{id: string, data: array<string, mixed>}>
     */
    public function queryDocuments(string $collection, array $filters, ?string $orderBy = null, ?int $limit = null): array
    {
        $fieldFilters = [];
        foreach ($filters as $field => $value) {
            $fieldFilters[] = [
                'fieldFilter' => [
                    'field' => ['fieldPath' => (string) $field],
                    'op' => 'EQUAL',
                    'value' => Value::encode($value),
                ],
            ];
        }

        $query = ['from' => [['collectionId' => $collection]]];
        if (count($fieldFilters) === 1) {
            $query['where'] = $fieldFilters[0];
        } elseif ($fieldFilters !== []) {
            $query['where'] = ['compositeFilter' => ['op' => 'AND', 'filters' => $fieldFilters]];
        }
        if ($orderBy !== null) {
            [$field, $direction] = array_pad(preg_split('/\s+/', trim($orderBy), 2) ?: [], 2, 'asc');
            $query['orderBy'] = [[
                'field' => ['fieldPath' => $field],
                'direction' => strtolower($direction) === 'desc' ? 'DESCENDING' : 'ASCENDING',
            ]];
        }
        if ($limit !== null) {
            $query['limit'] = max(1, min(3000, $limit));
        }

        $url = self::BASE . '/projects/' . rawurlencode($this->projectId)
            . '/databases/(default)/documents:runQuery';
        $rows = $this->request('POST', $url, ['structuredQuery' => $query]) ?? [];
        $documents = [];
        foreach ($rows as $row) {
            $document = is_array($row) ? ($row['document'] ?? null) : null;
            if (!is_array($document)) {
                continue;
            }
            $documents[] = [
                'id' => self::idFromName((string) ($document['name'] ?? '')),
                'data' => Value::decodeFields($document['fields'] ?? []),
            ];
        }
        return $documents;
    }

    /**
     * Página estable de una ventana temporal. El nombre del documento deshace
     * empates de fecha para que el cursor no omita recordatorios simultáneos.
     *
     * @param array{fecha: \DateTimeImmutable, nombre: string}|null $after
     * @return list<array{id: string, data: array<string, mixed>, cursor: array{fecha: \DateTimeImmutable, nombre: string}}>
     */
    public function queryDateWindowPage(string $collection, string $field, \DateTimeImmutable $start, \DateTimeImmutable $end, int $limit = 100, ?array $after = null): array
    {
        $query = self::dateWindowQuery($collection, $field, $start, $end, $limit, $after);
        $url = self::BASE . '/projects/' . rawurlencode($this->projectId)
            . '/databases/(default)/documents:runQuery';
        $rows = $this->request('POST', $url, ['structuredQuery' => $query]) ?? [];
        $documents = [];
        foreach ($rows as $row) {
            $document = is_array($row) ? ($row['document'] ?? null) : null;
            if (!is_array($document)) continue;
            $data = Value::decodeFields($document['fields'] ?? []);
            $fecha = $data[$field] ?? null;
            $nombre = $document['name'] ?? null;
            if (!$fecha instanceof \DateTimeImmutable || !is_string($nombre) || $nombre === '') {
                throw new HttpException(502, 'Firestore devolvio un cursor de recordatorio invalido.');
            }
            $documents[] = [
                'id' => self::idFromName($nombre),
                'data' => $data,
                'cursor' => ['fecha' => $fecha, 'nombre' => $nombre],
            ];
        }
        return $documents;
    }

    /** @param array{fecha: \DateTimeImmutable, nombre: string}|null $after */
    public static function dateWindowQuery(string $collection, string $field, \DateTimeImmutable $start, \DateTimeImmutable $end, int $limit, ?array $after = null): array
    {
        $limit = max(1, min(500, $limit));
        $query = [
            'from' => [['collectionId' => $collection]],
            'where' => ['compositeFilter' => [
                'op' => 'AND',
                'filters' => [
                    ['fieldFilter' => ['field' => ['fieldPath' => 'completado'], 'op' => 'EQUAL', 'value' => Value::encode(false)]],
                    ['fieldFilter' => ['field' => ['fieldPath' => $field], 'op' => 'GREATER_THAN_OR_EQUAL', 'value' => Value::encode($start)]],
                    ['fieldFilter' => ['field' => ['fieldPath' => $field], 'op' => 'LESS_THAN_OR_EQUAL', 'value' => Value::encode($end)]],
                ],
            ]],
            'orderBy' => [
                ['field' => ['fieldPath' => $field], 'direction' => 'ASCENDING'],
                ['field' => ['fieldPath' => '__name__'], 'direction' => 'ASCENDING'],
            ],
            'limit' => $limit,
        ];
        if ($after !== null) {
            $query['startAt'] = [
                'values' => [Value::encode($after['fecha']), ['referenceValue' => $after['nombre']]],
                'before' => false,
            ];
        }
        return $query;
    }

    /**
     * @return array<string, mixed>|null  null si el documento no existe.
     */
    public function getDocument(string $documentPath): ?array
    {
        $response = $this->request('GET', $this->documentsUrl($documentPath), allowNotFound: true);

        if ($response === null) {
            return null;
        }

        return Value::decodeFields($response['fields'] ?? []);
    }

    /** @return array{data: array<string, mixed>, updateTime: string}|null */
    public function getDocumentVersioned(string $documentPath): ?array
    {
        $response = $this->request('GET', $this->documentsUrl($documentPath), allowNotFound: true);
        if ($response === null) return null;
        return [
            'data' => Value::decodeFields($response['fields'] ?? []),
            'updateTime' => (string) ($response['updateTime'] ?? ''),
        ];
    }

    public function documentExists(string $documentPath): bool
    {
        return $this->getDocument($documentPath) !== null;
    }

    /**
     * Crea un documento. Si no se pasa id, lo genera Firestore.
     *
     * @param array<string, mixed> $data
     *
     * @return array{id: string, data: array<string, mixed>}
     */
    public function createDocument(string $collectionPath, array $data, ?string $documentId = null): array
    {
        DocumentBudget::assertFits($data);
        $id = $documentId ?? bin2hex(random_bytes(16));
        $this->commit([['type' => 'create', 'path' => $collectionPath . '/' . $id, 'data' => $data]]);
        return ['id' => $id, 'data' => $data];
    }

    /**
     * Actualiza solo los campos indicados (merge). Sin updateMask, Firestore
     * borraria todo lo que no venga en el cuerpo, que no es lo que se espera
     * de un PATCH parcial.
     *
     * @param array<string, mixed> $data
     *
     * @return array{id: string, data: array<string, mixed>}
     */
    public function patchDocument(string $documentPath, array $data, ?string $expectedUpdateTime = null): array
    {
        DocumentBudget::assertFits($data);
        $existing = $this->getDocumentVersioned($documentPath);
        if ($existing === null) throw HttpException::notFound('El recurso no existe.');
        $this->commit([['type' => 'patch', 'path' => $documentPath, 'data' => $data,
            'updateTime' => $expectedUpdateTime ?? $existing['updateTime']]]);
        return ['id' => self::idFromName($documentPath), 'data' => array_merge($existing['data'], $data)];
    }

    public function deleteDocument(string $documentPath): void
    {
        $existing = $this->getDocumentVersioned($documentPath);
        if ($existing !== null) $this->commit([['type' => 'delete', 'path' => $documentPath, 'updateTime' => $existing['updateTime']]]);
    }

    /**
     * Borra una coleccion documento a documento. La API REST de Firestore no
     * tiene borrado recursivo: hay que recorrerla.
     */
    public function deleteCollection(string $collectionPath): void
    {
        foreach ($this->listDocuments($collectionPath) as $document) {
            $this->deleteDocument($collectionPath . '/' . $document['id']);
        }
    }

    /**
     * @param array<string, string>|list<array{0: string, 1: string}> $query
     */
    private function documentsUrl(string $path, array $query = []): string
    {
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));

        $url = self::BASE . '/projects/' . rawurlencode($this->projectId)
            . '/databases/(default)/documents/' . $encodedPath;

        if ($query === []) {
            return $url;
        }

        // Se arma a mano porque updateMask.fieldPaths se repite varias veces y
        // http_build_query lo convertiria en "clave[0]=...".
        $pairs = [];
        foreach ($query as $key => $value) {
            [$name, $item] = is_array($value) ? $value : [(string) $key, $value];
            $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $item);
        }

        return $url . '?' . implode('&', $pairs);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $url, ?array $body = null, bool $allowNotFound = false): ?array
    {
        $response = $this->transport !== null ? ($this->transport)($method, $url, $body) : HttpClient::send(
            $method,
            $url,
            ['Authorization' => 'Bearer ' . $this->tokens->token()],
            $body,
            15,
        );

        $status = $response['status'];
        $payload = Json::decodeObject($response['body']) ?? [];

        if ($status >= 200 && $status < 300) {
            if ($status !== 204 && Json::decodeObject($response['body']) === null) {
                throw new HttpException(502, 'Respuesta de Firestore inválida.', errorCode: 'DEPENDENCY_RESPONSE_INVALID');
            }
            return $payload;
        }

        if ($status === 404) {
            if ($allowNotFound) {
                return null;
            }

            throw HttpException::notFound('El recurso no existe.');
        }

        if ($status === 409) {
            throw new HttpException(409, 'Ya existe un documento con ese id.');
        }

        $conditionalWrite = str_ends_with($url, ':commit')
            || ($method === 'PATCH' && str_contains($url, 'currentDocument.'));
        if ($status === 412 || ($status === 400 && $conditionalWrite
            && ($payload['error']['status'] ?? null) === 'FAILED_PRECONDITION')) {
            throw new HttpException(409, 'El documento cambio durante la operacion.');
        }

        if ($status === 400) {
            throw new HttpException(400, 'No se pudo realizar la operación solicitada.', errorCode: 'FIRESTORE_INVALID_OPERATION');
        }

        if ($status === 401 || $status === 403) {
            // Esto es culpa de la configuracion del servidor, no del cliente:
            // credenciales mal puestas o API de Firestore sin habilitar.
            throw new HttpException(500, 'El backend no tiene permiso sobre Firestore.', errorCode: 'FIRESTORE_PERMISSION_ERROR');
        }

        throw new HttpException(502, 'Firestore no pudo completar la operación.', errorCode: 'FIRESTORE_UNAVAILABLE');
    }

    /** "projects/p/databases/(default)/documents/users/u/mascotas/abc" => "abc" */
    private static function idFromName(string $name): string
    {
        $parts = explode('/', $name);

        return $parts === [] ? '' : (string) end($parts);
    }
}
