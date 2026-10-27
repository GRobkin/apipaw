<?php

declare(strict_types=1);

namespace PawLife\Resources;

use DateTimeImmutable;
use PawLife\Auth\AuthenticatedUser;
use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;
use PawLife\Http\Request;
use PawLife\Http\Response;

/**
 * CRUD generico sobre una coleccion de Firestore, configurado con un Schema.
 *
 * Las consultas se filtran por el uid del token verificado, nunca por el
 * userId que mande el cliente. Ese es el mecanismo que impide que un usuario
 * lea o escriba datos de otro, porque la
 * service account con la que escribe el backend ignora firestore.rules.
 */
final class ResourceController
{
    /**
     * @param callable(AuthenticatedUser, array<string, string>): string      $collectionPath
     * @param (callable(AuthenticatedUser, array<string, string>): string)|null $parentPath
     *        documento del que cuelga la coleccion (la mascota). Si se indica y
     *        no existe, se responde 404 en vez de crear datos huerfanos.
     * @param list<string> $subcollections  colecciones hijas que hay que borrar
     *        en cascada, porque Firestore no borra los descendientes de un
     *        documento al borrarlo: se quedarian inaccesibles pero ocupando.
     */
    public function __construct(
        private readonly Schema $schema,
        private readonly FirestoreClient $firestore,
        private readonly mixed $collectionPath,
        private readonly mixed $parentPath = null,
        private readonly array $subcollections = [],
        private readonly mixed $scope = null,
        private readonly mixed $cascadePlan = null,
        private readonly mixed $validateWrite = null,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params, AuthenticatedUser $user): Response
    {
        $path = $this->resolveCollection($user, $params);

        $orderBy = $this->schema->validateOrderBy($request->queryParam('orderBy', $this->schema->defaultOrderBy));
        $limit = $request->queryParam('limit');

        if ($limit !== null && (!ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 3000)) {
            throw HttpException::badRequest('El límite debe estar entre 1 y 3000.');
        }
        $numericLimit = $limit === null ? null : (int) $limit;
        $filters = $this->scope === null ? [] : ($this->scope)($user, $params);
        if ($request->queryParam('pageSize') !== null) {
            $size = $request->queryParam('pageSize');
            if (!ctype_digit($size) || (int) $size < 1 || (int) $size > 100) {
                throw HttpException::badRequest('El tamaño de página debe estar entre 1 y 100.');
            }
            $cap = match ($path) { 'mascotas' => 4, 'paseos' => 2, default => 100 };
            $page = $this->firestore->queryPage($path, $filters, $orderBy,
                min((int) $size, $numericLimit ?? 100, $cap), $request->queryParam('cursor'));
            return Response::ok([
                'items' => array_map(fn (array $doc): array => $this->schema->toJson($doc['id'], $doc['data']), $page['documents']),
                'nextCursor' => $page['nextCursor'],
            ]);
        }
        $documents = $filters === []
            ? $this->firestore->listDocuments($path, $orderBy, $numericLimit)
            : $this->firestore->queryDocuments($path, $filters, $orderBy, $numericLimit);

        $items = array_map(
            fn (array $doc) => $this->schema->toJson($doc['id'], $doc['data']),
            $documents,
        );

        return Response::ok(['items' => $items, 'total' => count($items)]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params, AuthenticatedUser $user): Response
    {
        $id = $this->idFrom($params);
        $path = $this->resolveCollection($user, $params) . '/' . $id;

        $data = $this->firestore->getDocument($path);
        if ($data === null) {
            throw HttpException::notFound("No existe {$this->schema->name} con id $id.");
        }
        $this->assertOwned($data, $user, $params);

        return Response::ok($this->schema->toJson($id, $data));
    }

    /** @param array<string, string> $params */
    public function store(Request $request, array $params, AuthenticatedUser $user): Response
    {
        $path = $this->resolveCollection($user, $params);

        $data = $this->schema->forCreate($request->body);
        $data = PhotoInput::prepare($data, beforeDecode: fn () => (new \PawLife\Security\WriteQuota($this->firestore))->reservePhotoAttempt($user->uid));
        if ($this->scope !== null) {
            $data = array_merge($data, ($this->scope)($user, $params));
        }
        if ($this->validateWrite !== null) {
            ($this->validateWrite)($data, $user);
        }

        $now = new DateTimeImmutable();
        $data['creadoEn'] = $now;
        $data['actualizadoEn'] = $now;
        \PawLife\Firestore\DocumentBudget::assertFits($data);

        $created = $this->firestore->createDocument($path, $data, self::requestedId($request));

        return Response::created($this->schema->toJson($created['id'], $created['data']));
    }

    /**
     * Id propuesto por el cliente en el cuerpo. Normalmente se deja que lo
     * genere Firestore, pero poder fijarlo sirve para datos con identidad
     * conocida de antemano y hace que crear sea idempotente: repetir la misma
     * peticion da 409 en vez de duplicar el documento.
     */
    private static function requestedId(Request $request): ?string
    {
        $raw = $request->body['id'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $id = trim($raw);

        // Las barras partirian la ruta del documento y ".." la sacaria de sitio.
        if (str_contains($id, '/') || $id === '.' || $id === '..' || strlen($id) > 1500) {
            throw HttpException::badRequest('El id propuesto no es valido.');
        }

        return $id;
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params, AuthenticatedUser $user): Response
    {
        $id = $this->idFrom($params);
        $path = $this->resolveCollection($user, $params) . '/' . $id;

        $versioned = $this->firestore->getDocumentVersioned($path);
        if ($versioned === null) {
            throw HttpException::notFound("No existe {$this->schema->name} con id $id.");
        }
        $existing = $versioned['data'];
        $this->assertOwned($existing, $user, $params);

        $data = $this->schema->forUpdate($request->body);
        $data = PhotoInput::prepare($data, $existing, fn () => (new \PawLife\Security\WriteQuota($this->firestore))->reservePhotoAttempt($user->uid));
        if ($this->validateWrite !== null) {
            ($this->validateWrite)(array_merge($existing, $data), $user);
        }
        $data['actualizadoEn'] = new DateTimeImmutable();
        \PawLife\Firestore\DocumentBudget::assertFits(array_merge($existing, $data));

        $updated = $this->firestore->patchDocument($path, $data, $versioned['updateTime']);

        return Response::ok($this->schema->toJson($updated['id'], $updated['data']));
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, array $params, AuthenticatedUser $user): Response
    {
        $id = $this->idFrom($params);
        $collection = $this->resolveCollection($user, $params);
        $path = $collection . '/' . $id;

        $existing = $this->firestore->getDocument($path);
        if ($existing === null) {
            return Response::noContent();
        }
        $this->assertOwned($existing, $user, $params);

        foreach ($this->subcollections as $subcollection) {
            $this->firestore->deleteCollection("$path/$subcollection");
        }

        if ($this->cascadePlan !== null) {
            foreach (($this->cascadePlan)($user, $params, $id) as $relatedCollection => $plan) {
                $filters = $plan['filters'] ?? $plan;
                $matches = $plan['matches'] ?? [];
                foreach ($this->firestore->queryDocuments($relatedCollection, $filters) as $document) {
                    foreach ($matches as $field => $expected) {
                        if (($document['data'][$field] ?? null) !== $expected) {
                            continue 2;
                        }
                    }
                    $this->firestore->deleteDocument($relatedCollection . '/' . $document['id']);
                }
            }
        }

        $this->firestore->deleteDocument($path);

        return Response::noContent();
    }

    /** @param array<string, string> $params */
    private function resolveCollection(AuthenticatedUser $user, array $params): string
    {
        if ($this->parentPath !== null) {
            $parent = ($this->parentPath)($user, $params);
            $parentData = $this->firestore->getDocument($parent);
            if ($parentData === null || ($parentData['userId'] ?? null) !== $user->uid) {
                $mascotaId = $params['mascotaId'] ?? '?';
                throw HttpException::notFound("No existe la mascota $mascotaId.");
            }
        }

        return ($this->collectionPath)($user, $params);
    }

    /** @param array<string, mixed> $data @param array<string, string> $params */
    private function assertOwned(array $data, AuthenticatedUser $user, array $params): void
    {
        if ($this->scope === null) {
            return;
        }
        foreach (($this->scope)($user, $params) as $field => $expected) {
            if (($data[$field] ?? null) !== $expected) {
                throw HttpException::notFound("No existe {$this->schema->name}.");
            }
        }
    }

    /** @param array<string, string> $params */
    private function idFrom(array $params): string
    {
        $id = $params['id'] ?? '';
        if ($id === '') {
            throw HttpException::badRequest('Falta el id en la ruta.');
        }

        return $id;
    }
}
