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
use PawLife\Notifications\ReminderRateLimiter;
use PawLife\Support\Env;

require dirname(__DIR__) . '/src/bootstrap.php';

$origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : null;
try {
    $request = Request::fromGlobals();
    if ($request->method === 'GET' && \PawLife\Http\NotificationPanel::serve($request->path)) exit;

    // El preflight del navegador no lleva token: se contesta antes de tocar nada.
    if ($request->method === 'OPTIONS') {
        http_response_code(204);
        Response::sendCorsHeaders($origin);
        exit;
    }

    $router = new Router();
    $router->get('/api/panel-config', static fn (): Response => Response::ok(
        \PawLife\Support\Json::decodeObject((string) file_get_contents(dirname(__DIR__) . '/panel/firebase-config.json')) ?? [],
    ));

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

    // Cloudflare firma cada llamada. La clave privada solo vive en el Worker;
    // la API conserva la clave publica y las credenciales de Firebase.
    $router->post('/api/internal/send-reminders', static function (Request $r): Response {
        \PawLife\Notifications\CronSignature::verify($r);
        $result = (new \PawLife\Notifications\PushDispatcher(
            FirestoreClient::fromEnv(),
            new \PawLife\Notifications\FcmSender(),
        ))->dispatch(dryRun: $r->queryParam('dryRun') === '1');
        return Response::ok($result);
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

    $router->get('/api/mascotas/{mascotaId}/historial-paseos', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($db): Response {
        $page = $db()->walkHistoryPage($u->uid, $p['mascotaId'], $r->queryParam('cursor'));
        return Response::ok([
            'items' => array_map(static fn (array $doc): array => Catalog::paseos()->toJson($doc['id'], $doc['data']), $page['documents']),
            'nextCursor' => $page['nextCursor'],
        ]);
    }));

    $admin = static function (AuthenticatedUser $u) use ($db): void {
        \PawLife\Auth\NotificationAdmin::assertAllowed($u, $db());
    };
    $router->get('/api/admin/session', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($admin): Response {
        $admin($u);
        return Response::ok(['email' => $u->email, 'uid' => $u->uid]);
    }));
    $router->get('/api/admin/notification-users', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($admin, $db): Response {
        $admin($u);
        $email = trim($r->queryParam('email', '') ?? '');
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw HttpException::badRequest('Escribí el correo completo del destinatario.');
        }
        $profile = FirebaseAuthAdmin::findByEmail($email);
        if ($profile === null || $db()->getDocument('users/' . $profile['uid']) === null) {
            return Response::ok(['items' => []]);
        }
        return Response::ok(['items' => [$profile]]);
    }));
    $router->post('/api/admin/notifications', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($admin, $db): Response {
        $admin($u);
        return Response::ok((new \PawLife\Notifications\ManualNotification($db(), new \PawLife\Notifications\FcmSender()))->send($u->uid, $r->body));
    }));

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
        if (($data['completado'] ?? false) !== true) {
            (new ReminderRateLimiter($db()))->consume($u->uid);
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
        $u->requireRecentAuthentication();
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
        $db()->deleteDocument(ReminderRateLimiter::documentPath($u->uid));
        $db()->deleteDocument(ReminderRateLimiter::documentPath('admin-notification:' . $u->uid));
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
