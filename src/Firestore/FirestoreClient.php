<?php

declare(strict_types=1);

namespace PawLife\Firestore;

use PawLife\Auth\ServiceAccount;
use PawLife\Http\HttpException;
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
 * reglas sino de que cada ruta se construya bajo users/{uid}, con el uid
 * sacado del ID token verificado (ver Auth\FirebaseTokenVerifier).
 */
final class FirestoreClient
{
    private const BASE = 'https://firestore.googleapis.com/v1';

    /** Tope de paginas al listar, para que una coleccion enorme no agote la funcion. */
    private const MAX_PAGES = 10;

    public function __construct(
        private readonly string $projectId,
        private readonly AccessTokenProvider $tokens,
    ) {
    }

    public static function fromEnv(): self
    {
        $serviceAccount = ServiceAccount::fromEnv();

        return new self(
            Env::get('FIREBASE_PROJECT_ID', $serviceAccount->projectId) ?? $serviceAccount->projectId,
            new AccessTokenProvider($serviceAccount),
        );
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

        return $documents;
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
        $query = $documentId !== null ? ['documentId' => $documentId] : [];

        $response = $this->request(
            'POST',
            $this->documentsUrl($collectionPath, $query),
            ['fields' => Value::encodeFields($data)],
        );

        return [
            'id' => self::idFromName((string) ($response['name'] ?? '')),
            'data' => Value::decodeFields($response['fields'] ?? []),
        ];
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
    public function patchDocument(string $documentPath, array $data): array
    {
        $query = [];
        foreach (array_keys($data) as $field) {
            $query[] = ['updateMask.fieldPaths', (string) $field];
        }
        // Exige que el documento ya exista: si no, un PATCH sobre un id
        // inventado lo crearia en silencio y el cliente creeria que edito algo.
        $query[] = ['currentDocument.exists', 'true'];

        $response = $this->request(
            'PATCH',
            $this->documentsUrl($documentPath, $query),
            ['fields' => Value::encodeFields($data)],
        );

        return [
            'id' => self::idFromName((string) ($response['name'] ?? '')),
            'data' => Value::decodeFields($response['fields'] ?? []),
        ];
    }

    public function deleteDocument(string $documentPath): void
    {
        $this->request('DELETE', $this->documentsUrl($documentPath), allowNotFound: true);
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
        $response = HttpClient::send(
            $method,
            $url,
            ['Authorization' => 'Bearer ' . $this->tokens->token()],
            $body,
            15,
        );

        $status = $response['status'];
        $payload = Json::decodeObject($response['body']) ?? [];

        if ($status >= 200 && $status < 300) {
            return $payload;
        }

        if ($status === 404) {
            if ($allowNotFound) {
                return null;
            }

            throw HttpException::notFound('El recurso no existe.');
        }

        $message = (string) ($payload['error']['message'] ?? $response['body']);

        if ($status === 409) {
            throw new HttpException(409, 'Ya existe un documento con ese id.');
        }

        if ($status === 400) {
            throw HttpException::badRequest("Firestore rechazo la operacion: $message");
        }

        if ($status === 401 || $status === 403) {
            // Esto es culpa de la configuracion del servidor, no del cliente:
            // credenciales mal puestas o API de Firestore sin habilitar.
            throw new HttpException(500, "El backend no tiene permiso sobre Firestore: $message");
        }

        throw new HttpException(502, "Firestore respondio $status: $message");
    }

    /** "projects/p/databases/(default)/documents/users/u/mascotas/abc" => "abc" */
    private static function idFromName(string $name): string
    {
        $parts = explode('/', $name);

        return $parts === [] ? '' : (string) end($parts);
    }
}
