<?php

declare(strict_types=1);

/**
 * Front controller de la API de PawLife.
 *
 * En Vercel todo el trafico se reescribe a este archivo (ver vercel.json), asi
 * que aqui se monta el router entero. En local se consigue lo mismo con:
 *
 *   php -S localhost:8000 api/index.php
 */

use PawLife\Auth\AuthenticatedUser;
use PawLife\Auth\FirebaseTokenVerifier;
use PawLife\Auth\FirebaseAuthAdmin;
use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;
use PawLife\Http\Request;
use PawLife\Http\Response;
use PawLife\Http\Router;
use PawLife\Resources\Catalog;
use PawLife\Resources\CareValidator;
use PawLife\Resources\ResourceController;
use PawLife\Resources\Schema;
use PawLife\Support\Env;

require dirname(__DIR__) . '/src/bootstrap.php';

$request = Request::fromGlobals();
$origin = $request->header('origin');

// El preflight del navegador no lleva token: se contesta antes de tocar nada.
if ($request->method === 'OPTIONS') {
    http_response_code(204);
    Response::sendCorsHeaders($origin);
    exit;
}

try {
    $router = new Router();

    // Sin autenticacion: sirve para comprobar que el despliegue esta vivo y que
    // las variables de entorno llegaron, sin necesidad de un token.
    $router->get('/api/health', static function (): Response {
        return Response::ok([
            'ok' => true,
            'servicio' => 'pawlife-api',
            'hora' => gmdate('c'),
            'proyecto' => Env::get('FIREBASE_PROJECT_ID'),
            'credenciales' => Env::get('FIREBASE_SERVICE_ACCOUNT') !== null,
        ]);
    });

    // El usuario se verifica una sola vez y solo cuando la ruta lo necesita,
    // para que /api/health siga respondiendo aunque el token sea invalido.
    $user = null;
    $currentUser = static function () use (&$user, $request): AuthenticatedUser {
        if ($user === null) {
            $user = FirebaseTokenVerifier::fromEnv()->verify($request->bearerToken());
        }

        return $user;
    };

    $firestore = null;
    $db = static function () use (&$firestore): FirestoreClient {
        return $firestore ??= FirestoreClient::fromEnv();
    };

    /** Envuelve un handler para que reciba el usuario ya verificado. */
    $auth = static function (callable $handler) use ($currentUser): callable {
        return static function (Request $request, array $params) use ($handler, $currentUser): Response {
            return $handler($request, $params, $currentUser());
        };
    };

    $ensurePerfil = static function (AuthenticatedUser $u) use ($db): array {
        $path = "users/{$u->uid}";
        $data = $db()->getDocument($path);
        if ($data !== null) {
            return $data;
        }
        $now = new DateTimeImmutable();
        $inicial = [
            'email' => $u->email,
            'nombre' => $u->name ?? '',
            'fotoUrl' => $u->pictureUrl,
            'premium' => false,
            'premiumHasta' => null,
            'zonaHoraria' => 'America/Montevideo',
            'creadoEn' => $now,
            'actualizadoEn' => $now,
        ];
        try {
            return $db()->createDocument('users', $inicial, $u->uid)['data'];
        } catch (HttpException $e) {
            if ($e->status() !== 409) {
                throw $e;
            }
            // Otro dispositivo pudo crear el perfil al mismo tiempo.
            return $db()->getDocument($path) ?? throw $e;
        }
    };

    $router->get('/api/me', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($ensurePerfil): Response {
        return Response::ok($u->toArray() + [
            'perfil' => Catalog::perfil()->toJson($u->uid, $ensurePerfil($u)),
        ]);
    }));

    $router->patch('/api/me', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($db, $ensurePerfil): Response {
        $data = Catalog::perfilEditable()->forUpdate($r->body);
        $ensurePerfil($u);
        $data['actualizadoEn'] = new DateTimeImmutable();
        $actualizado = $db()->patchDocument("users/{$u->uid}", $data);
        return Response::ok(Catalog::perfil()->toJson($u->uid, $actualizado['data']));
    }));

    /**
     * Registra las cinco rutas del CRUD de un recurso.
     *
     * @param string $base         ej. "/api/mascotas/{mascotaId}/vacunas"
     * @param callable(AuthenticatedUser, array<string, string>): string $collectionPath
     */
    $resource = static function (
        string $base,
        Schema $schema,
        callable $collectionPath,
        ?callable $parentPath = null,
        array $subcollections = [],
        ?callable $scope = null,
        ?callable $cascadePlan = null,
        ?callable $validateWrite = null,
    ) use ($router, $auth, $db): void {
        // El controlador se construye en la primera peticion que lo use, no al
        // registrar la ruta: asi /api/health sigue contestando aunque falten
        // las credenciales de Firestore, que es justo cuando hace falta.
        $controller = null;
        $resolve = static function () use (
            &$controller,
            $schema,
            $db,
            $collectionPath,
            $parentPath,
            $subcollections,
            $scope,
            $cascadePlan,
            $validateWrite,
        ): ResourceController {
            return $controller ??= new ResourceController(
                $schema,
                $db(),
                $collectionPath,
                $parentPath,
                $subcollections,
                $scope,
                $cascadePlan,
                $validateWrite,
            );
        };

        $action = static function (string $method) use ($auth, $resolve): callable {
            return $auth(
                static fn (Request $r, array $p, AuthenticatedUser $u): Response => $resolve()->$method($r, $p, $u),
            );
        };

        $router->get($base, $action('index'));
        $router->post($base, $action('store'));
        $router->get("$base/{id}", $action('show'));
        $router->patch("$base/{id}", $action('update'));
        // PUT se acepta como alias de PATCH: es un merge parcial igualmente,
        // pero ahorra sorpresas a cualquier cliente que solo hable PUT.
        $router->put("$base/{id}", $action('update'));
        $router->delete("$base/{id}", $action('destroy'));
    };

    $mascotasPath = static fn (AuthenticatedUser $u, array $p): string => 'mascotas';
    $mascotaDoc = static fn (AuthenticatedUser $u, array $p): string => "mascotas/{$p['mascotaId']}";
    $porUsuario = static fn (AuthenticatedUser $u, array $p): array => ['userId' => $u->uid];
    $porMascota = static fn (AuthenticatedUser $u, array $p): array => [
        'userId' => $u->uid,
        'mascotaId' => $p['mascotaId'],
    ];

    $resource(
        '/api/mascotas',
        Catalog::mascotas(),
        $mascotasPath,
        null,
        // Al borrar la mascota se lleva por delante todo lo que cuelga de ella.
        [],
        $porUsuario,
        static fn (AuthenticatedUser $u, array $p, string $id): array => array_fill_keys(
            ['vacunas', 'medicamentos', 'alimentaciones', 'pesos', 'paseos', 'recordatorios'],
            ['userId' => $u->uid, 'mascotaId' => $id],
        ),
    );

    foreach ([
        'vacunas' => Catalog::vacunas(),
        'medicamentos' => Catalog::medicamentos(),
        'pesos' => Catalog::pesos(),
        'paseos' => Catalog::paseos(),
        'alimentaciones' => Catalog::alimentaciones(),
    ] as $nombre => $schema) {
        $cascade = in_array($nombre, ['vacunas', 'medicamentos'], true)
            ? static fn (AuthenticatedUser $u, array $p, string $id): array => [
                'recordatorios' => [
                    'filters' => ['userId' => $u->uid],
                    'matches' => [
                        'mascotaId' => $p['mascotaId'],
                        'tipo' => $nombre === 'vacunas' ? 'vacuna' : 'medicamento',
                        'entidadId' => $id,
                    ],
                ],
            ]
            : null;
        $resource(
            "/api/mascotas/{mascotaId}/$nombre",
            $schema,
            static fn (AuthenticatedUser $u, array $p): string => $nombre,
            $mascotaDoc,
            [],
            $porMascota,
            $cascade,
            static function (array $data, AuthenticatedUser $u) use ($nombre): void {
                CareValidator::check($nombre, $data);
            },
        );
    }

    $validateRecordatorio = static function (array $data, AuthenticatedUser $u) use ($db): void {
        $mascotaId = $data['mascotaId'] ?? null;
        $mascota = is_string($mascotaId) && $mascotaId !== ''
            ? $db()->getDocument("mascotas/$mascotaId") : null;
        if ($mascota === null || ($mascota['userId'] ?? null) !== $u->uid) {
            throw HttpException::badRequest('La mascota del recordatorio no existe o no pertenece a la cuenta.');
        }
        $collection = match ($data['tipo'] ?? null) {
            'vacuna' => 'vacunas',
            'medicamento' => 'medicamentos',
            default => null,
        };
        $entityId = $data['entidadId'] ?? null;
        if ($entityId !== null && $entityId !== '') {
            if ($collection === null || !is_string($entityId)) {
                throw HttpException::badRequest('La entidad del recordatorio no corresponde al tipo.');
            }
            $entity = $db()->getDocument("$collection/$entityId");
            if ($entity === null || ($entity['userId'] ?? null) !== $u->uid
                || ($entity['mascotaId'] ?? null) !== $mascotaId) {
                throw HttpException::badRequest('La entidad del recordatorio no pertenece a esa mascota.');
            }
        }
    };

    $resource(
        '/api/recordatorios',
        Catalog::recordatorios(),
        static fn (AuthenticatedUser $u, array $p): string => 'recordatorios',
        null,
        [],
        $porUsuario,
        null,
        $validateRecordatorio,
    );

    $router->delete('/api/me', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($db): Response {
        $legacyRoot = "users/{$u->uid}";
        foreach ($db()->listDocuments("$legacyRoot/mascotas") as $pet) {
            $petPath = "$legacyRoot/mascotas/{$pet['id']}";
            foreach (['vacunas', 'medicamentos', 'alimentaciones', 'pesos', 'paseos'] as $child) {
                $db()->deleteCollection("$petPath/$child");
            }
            $db()->deleteDocument($petPath);
        }
        foreach (['recordatorios', 'dispositivos'] as $child) {
            $db()->deleteCollection("$legacyRoot/$child");
        }
        foreach (['mascotas', 'vacunas', 'medicamentos', 'alimentaciones', 'pesos', 'paseos', 'recordatorios', 'dispositivos', 'notificaciones'] as $collection) {
            foreach ($db()->queryDocuments($collection, ['userId' => $u->uid]) as $document) {
                $db()->deleteDocument($collection . '/' . $document['id']);
            }
        }
        $db()->deleteDocument($legacyRoot);
        FirebaseAuthAdmin::deleteUser($u->uid);
        return Response::noContent();
    }));

    $resource(
        '/api/dispositivos',
        Catalog::dispositivos(),
        static fn (AuthenticatedUser $u, array $p): string => 'dispositivos',
        null,
        [],
        $porUsuario,
    );

    $router->dispatch($request)->send($origin);
} catch (HttpException $e) {
    Response::error($e->status(), $e->getMessage(), $e->details())->send($origin);
} catch (Throwable $e) {
    // El detalle va al log de Vercel, no al cliente: puede contener trozos de
    // configuracion o rutas internas.
    error_log('[pawlife-api] ' . $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());

    Response::error(500, 'Error interno del servidor.')->send($origin);
}
