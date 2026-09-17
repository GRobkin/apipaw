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
 * Todas las rutas se construyen a partir del uid del token verificado, nunca
 * de algo que mande el cliente en el cuerpo o en la URL. Ese es el unico
 * mecanismo que impide que un usuario lea o escriba datos de otro, porque la
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
    ) {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params, AuthenticatedUser $user): Response
    {
        $path = $this->resolveCollection($user, $params);

        $orderBy = $request->queryParam('orderBy', $this->schema->defaultOrderBy);
        $limit = $request->queryParam('limit');

        $documents = $this->firestore->listDocuments(
            $path,
            $orderBy,
            $limit !== null && ctype_digit($limit) ? max(1, (int) $limit) : null,
        );

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

        return Response::ok($this->schema->toJson($id, $data));
    }

    /** @param array<string, string> $params */
    public function store(Request $request, array $params, AuthenticatedUser $user): Response
    {
        $path = $this->resolveCollection($user, $params);

        $data = $this->schema->forCreate($request->body);

        $now = new DateTimeImmutable();
        $data['creadoEn'] = $now;
        $data['actualizadoEn'] = $now;

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

        $data = $this->schema->forUpdate($request->body);
        $data['actualizadoEn'] = new DateTimeImmutable();

        $updated = $this->firestore->patchDocument($path, $data);

        return Response::ok($this->schema->toJson($updated['id'], $updated['data']));
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, array $params, AuthenticatedUser $user): Response
    {
        $id = $this->idFrom($params);
        $collection = $this->resolveCollection($user, $params);
        $path = $collection . '/' . $id;

        foreach ($this->subcollections as $subcollection) {
            $this->firestore->deleteCollection("$path/$subcollection");
        }

        $this->firestore->deleteDocument($path);

        return Response::noContent();
    }

    /** @param array<string, string> $params */
    private function resolveCollection(AuthenticatedUser $user, array $params): string
    {
        if ($this->parentPath !== null) {
            $parent = ($this->parentPath)($user, $params);
            if (!$this->firestore->documentExists($parent)) {
                $mascotaId = $params['mascotaId'] ?? '?';
                throw HttpException::notFound("No existe la mascota $mascotaId.");
            }
        }

        return ($this->collectionPath)($user, $params);
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
